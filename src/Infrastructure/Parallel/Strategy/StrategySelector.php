<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel\Strategy;

use Psr\Log\LoggerInterface;

use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfigurationProviderInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\Strategy\ExecutionStrategyInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\Strategy\StrategySelectorInterface;
use RuntimeException;

/**
 * Selects and configures the best available execution strategy.
 *
 * Priority order:
 * 1. AmphpParallelStrategy - if amphp/parallel available and workers > 1
 * 2. SequentialStrategy - always available (fallback)
 *
 * Configuration is read from TransitionalRuntimeConfigurationProviderInterface:
 * - workers: number of parallel workers (null = auto-detect, 0/1 = sequential)
 * - projectRoot: project root directory (required for parallel)
 * - cacheDir: cache directory for AST caching
 * - cacheEnabled: whether caching is enabled
 *
 * Worker task metadata is owned by FileProcessingTaskFactory; this selector
 * configures only execution concerns that vary per analysis run.
 */
final class StrategySelector implements StrategySelectorInterface
{
    public function __construct(
        private readonly AmphpParallelStrategy $amphpStrategy,
        private readonly SequentialStrategy $sequentialStrategy,
        private readonly TransitionalRuntimeConfigurationProviderInterface $configurationProvider,
        private readonly WorkerCountDetector $workerCountDetector,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Returns the best available strategy for the current system and configuration.
     *
     * Configures the strategy based on:
     * - workers setting (null = auto-detect, 0/1 = sequential, >1 = parallel)
     * - projectRoot (required for parallel processing)
     * - cacheDir (optional, for AST caching in workers)
     */
    public function select(): ExecutionStrategyInterface
    {
        $config = $this->configurationProvider->getConfiguration();

        // Determine worker count
        $requestedWorkers = $config->workers;

        $this->logger->debug('StrategySelector: selecting strategy', [
            'requestedWorkers' => $requestedWorkers,
            'projectRoot' => $config->projectRoot->value(),
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

        // Project root is already an AbsolutePath after ADR 0015 Phase 5;
        // canonicalize via realpath() so worker-process cache keys remain
        // stable across symlinked invocations (e.g., /var/build → /opt/project).
        // canonicalize() throws RuntimeException when the path does not exist —
        // map that to the sequential fallback the user has expected since v0.x.
        try {
            $projectRoot = $config->projectRoot->canonicalize();
        } catch (RuntimeException) {
            $this->logger->warning(
                'StrategySelector: project root does not exist, using sequential fallback',
                ['projectRoot' => $config->projectRoot->value()],
            );

            return $this->sequentialStrategy;
        }
        $this->amphpStrategy->setProjectRoot($projectRoot);

        // Cache directory is already AbsolutePath-resolved against projectRoot in TransitionalRuntimeConfiguration.
        $this->amphpStrategy->setCacheDir($config->cacheEnabled ? $config->cacheDir : null);

        $this->logger->info(
            'StrategySelector: using parallel strategy',
            [
                'workers' => $workerCount,
                'projectRoot' => $projectRoot->value(),
                'cacheEnabled' => $config->cacheEnabled,
            ],
        );

        return $this->amphpStrategy;
    }
}
