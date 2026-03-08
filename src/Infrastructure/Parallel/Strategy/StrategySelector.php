<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Parallel\Strategy;

use AiMessDetector\Analysis\Collection\Strategy\ExecutionStrategyInterface;
use AiMessDetector\Analysis\Collection\Strategy\StrategySelectorInterface;
use AiMessDetector\Configuration\ConfigurationProviderInterface;
use AiMessDetector\Core\Metric\DerivedCollectorInterface;
use AiMessDetector\Core\Metric\MetricCollectorInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Selects and configures the best available execution strategy.
 *
 * Priority order:
 * 1. AmphpParallelStrategy - if amphp/parallel available and workers > 1
 * 2. SequentialStrategy - always available (fallback)
 *
 * Configuration is read from ConfigurationProviderInterface:
 * - workers: number of parallel workers (null = auto-detect, 0/1 = sequential)
 * - projectRoot: project root directory (required for parallel)
 * - cacheDir: cache directory for AST caching
 * - cacheEnabled: whether caching is enabled
 *
 * Collector classes are injected by ParallelCollectorClassesCompilerPass
 * to ensure workers use the same collectors as configured in DI.
 */
final class StrategySelector implements StrategySelectorInterface
{
    /**
     * @param list<class-string<MetricCollectorInterface>> $collectorClasses
     * @param list<class-string<DerivedCollectorInterface>> $derivedCollectorClasses
     */
    public function __construct(
        private readonly AmphpParallelStrategy $amphpStrategy,
        private readonly SequentialStrategy $sequentialStrategy,
        private readonly ConfigurationProviderInterface $configurationProvider,
        private readonly WorkerCountDetector $workerCountDetector,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly array $collectorClasses = [],
        private readonly array $derivedCollectorClasses = [],
    ) {}

    /**
     * Returns the best available strategy for the current system and configuration.
     *
     * Configures the strategy based on:
     * - workers setting (null = auto-detect, 0/1 = sequential, >1 = parallel)
     * - projectRoot (required for parallel processing)
     * - cacheDir (optional, for AST caching in workers)
     * - collectorClasses (for worker process synchronization)
     */
    public function select(): ExecutionStrategyInterface
    {
        $config = $this->configurationProvider->getConfiguration();

        // Determine worker count
        $requestedWorkers = $config->workers;

        $this->logger->debug('StrategySelector: selecting strategy', [
            'requestedWorkers' => $requestedWorkers,
            'projectRoot' => $config->projectRoot,
            'collectors' => \count($this->collectorClasses),
        ]);

        // Explicit sequential mode (workers = 0 or 1)
        if ($requestedWorkers === 0 || $requestedWorkers === 1) {
            $this->logger->debug('StrategySelector: sequential mode requested', [
                'workers' => $requestedWorkers,
            ]);

            return $this->sequentialStrategy;
        }

        // Check if parallel is available
        if (!$this->amphpStrategy->isAvailable()) {
            $this->logger->info(
                'StrategySelector: parallel not available, using sequential',
                ['reason' => 'amphp/parallel or pcntl extension not available'],
            );

            return $this->sequentialStrategy;
        }

        // Auto-detect or use requested worker count
        $workerCount = $requestedWorkers === null
            ? $this->workerCountDetector->detect()
            : $requestedWorkers;

        // If only 1 worker detected, use sequential
        if ($workerCount <= 1) {
            $this->logger->debug('StrategySelector: only 1 worker detected, using sequential');

            return $this->sequentialStrategy;
        }

        // Configure parallel strategy
        $this->amphpStrategy->setWorkerCount($workerCount);

        // Set collector classes for worker synchronization
        $this->amphpStrategy->setCollectorClasses($this->collectorClasses);
        $this->amphpStrategy->setDerivedCollectorClasses($this->derivedCollectorClasses);

        // Set project root (resolve to absolute path)
        $projectRoot = $config->projectRoot;
        if (!str_starts_with($projectRoot, '/')) {
            $projectRoot = getcwd() . '/' . $projectRoot;
        }
        $resolvedRoot = realpath($projectRoot);
        if ($resolvedRoot === false) {
            $this->logger->warning(
                'StrategySelector: project root does not exist, using sequential fallback',
                ['projectRoot' => $projectRoot],
            );

            return $this->sequentialStrategy;
        }
        $this->amphpStrategy->setProjectRoot($resolvedRoot);

        // Set cache directory if caching is enabled
        if ($config->cacheEnabled) {
            $cacheDir = $config->cacheDir;
            if (!str_starts_with($cacheDir, '/')) {
                $cacheDir = $projectRoot . '/' . $cacheDir;
            }
            $this->amphpStrategy->setCacheDir($cacheDir);
        } else {
            $this->amphpStrategy->setCacheDir(null);
        }

        $this->logger->info(
            'StrategySelector: using parallel strategy',
            [
                'workers' => $workerCount,
                'projectRoot' => $projectRoot,
                'cacheEnabled' => $config->cacheEnabled,
                'collectors' => \count($this->collectorClasses),
            ],
        );

        return $this->amphpStrategy;
    }
}
