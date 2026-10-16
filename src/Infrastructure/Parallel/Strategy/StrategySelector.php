<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel\Strategy;

use Psr\Log\LoggerInterface;

use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Run\Contract\Collection\Strategy\ExecutionStrategyInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\Strategy\StrategySelectorInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Cache\Contract\CacheOpener;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationStoreInterface;
use Qualimetrix\Infrastructure\Parallel\WorkerRequest;
use RuntimeException;

/**
 * Selects and configures an execution strategy from the worker request.
 *
 * Priority order:
 * 1. AmphpParallelStrategy - when requested workers exceed one
 * 2. SequentialStrategy - always available (fallback)
 *
 * Parallel owns the worker setting; Cache's run opener supplies the accepted
 * cache directory before either sequential or parallel selection.
 *
 * Worker task metadata is owned by FileProcessingTaskFactory; this selector
 * configures only execution concerns that vary per analysis run.
 */
final class StrategySelector implements StrategySelectorInterface
{
    public function __construct(
        private readonly AmphpParallelStrategy $amphpStrategy,
        private readonly SequentialStrategy $sequentialStrategy,
        private readonly ParallelConfigurationStoreInterface $configurationStore,
        private readonly CacheOpener $cacheOpener,
        private readonly WorkerCountDetector $workerCountDetector,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Returns the configured strategy; parallel worker startup is judged during execution.
     *
     * Configures the strategy based on:
     * - workers setting (null = auto-detect, 0/1 = sequential, >1 = parallel)
     * - projectRoot (required for parallel processing)
     * - cacheDir (optional, for AST caching in workers)
     */
    public function select(AbsolutePath $projectRoot): ExecutionStrategyInterface
    {
        $requestedWorkers = $this->configurationStore->current()->workers;
        $cacheDirectory = $this->cacheOpener->open();

        $this->logger->debug('StrategySelector: selecting strategy', [
            'requestedWorkers' => $requestedWorkers,
            'projectRoot' => $projectRoot->value(),
        ]);

        // Explicit sequential mode (workers = 0 or 1)
        if ($requestedWorkers === 0 || $requestedWorkers === 1) {
            $this->logger->debug('StrategySelector: sequential mode requested', [
                'workers' => $requestedWorkers,
            ]);

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
        $this->amphpStrategy->setWorkerRequest(new WorkerRequest($workerCount, $this->configurationStore->current()->workerSource));

        // Canonicalize the project identity passed to worker processes.
        // canonicalize() throws RuntimeException when the path does not exist —
        // map that to the sequential fallback the user has expected since v0.x.
        try {
            $projectRoot = $projectRoot->canonicalize();
        } catch (RuntimeException) {
            $this->logger->warning(
                'StrategySelector: project root does not exist, using sequential fallback',
                ['projectRoot' => $projectRoot->value()],
            );

            return $this->sequentialStrategy;
        }
        $this->amphpStrategy->setProjectRoot($projectRoot);

        // Cache directory is already AbsolutePath-resolved against projectRoot by Cache configuration.
        $this->amphpStrategy->setCacheDir($cacheDirectory);

        $this->logger->info(
            'StrategySelector: using parallel strategy',
            [
                'workers' => $workerCount,
                'projectRoot' => $projectRoot->value(),
                'cacheEnabled' => $cacheDirectory !== null,
            ],
        );

        return $this->amphpStrategy;
    }
}
