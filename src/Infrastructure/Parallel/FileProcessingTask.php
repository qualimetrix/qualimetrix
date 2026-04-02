<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Qualimetrix\Analysis\Collection\FileProcessingResult;
use Qualimetrix\Core\Metric\DerivedCollectorInterface;
use Qualimetrix\Core\Metric\MetricCollectorInterface;
use SplFileInfo;

/**
 * Task for processing a single PHP file in a worker process.
 *
 * This task is serialized and sent to a worker process where it:
 * 1. Bootstraps a minimal FileProcessor via WorkerBootstrap
 * 2. Processes the file and collects metrics
 * 3. Returns a serializable FileProcessingResult
 *
 * The collector classes are passed from the main process to ensure
 * workers use the same set of collectors as configured in DI container.
 *
 * @implements Task<FileProcessingResult, mixed, mixed>
 */
final class FileProcessingTask implements Task
{
    /**
     * @param string $filePath Absolute path to the PHP file to process
     * @param string $projectRoot Project root for autoloading
     * @param list<class-string<MetricCollectorInterface>> $collectorClasses Collector class names
     * @param list<class-string<DerivedCollectorInterface>> $derivedCollectorClasses Derived collector class names
     * @param string|null $cacheDir Optional cache directory for AST caching
     * @param array<string, mixed> $collectorConfig Collector-level configuration (e.g., LCOM exclude methods)
     */
    public function __construct(
        private readonly string $filePath,
        private readonly string $projectRoot,
        private readonly array $collectorClasses,
        private readonly array $derivedCollectorClasses = [],
        private readonly ?string $cacheDir = null,
        private readonly array $collectorConfig = [],
    ) {}

    /**
     * Executes the task in the worker process.
     *
     * @param Channel<mixed, mixed> $channel Communication channel (unused)
     * @param Cancellation $cancellation Cancellation token for graceful shutdown
     *
     * @throws \Amp\CancelledException If cancellation was requested before processing started
     *
     * @return FileProcessingResult The result of processing the file
     */
    public function run(Channel $channel, Cancellation $cancellation): FileProcessingResult
    {
        // Check for cancellation before starting work
        $cancellation->throwIfRequested();

        // Get or create FileProcessor via WorkerBootstrap
        // WorkerBootstrap caches the processor for reuse across tasks in the same worker
        $processor = WorkerBootstrap::getFileProcessor(
            projectRoot: $this->projectRoot,
            collectorClasses: $this->collectorClasses,
            derivedCollectorClasses: $this->derivedCollectorClasses,
            cacheDir: $this->cacheDir,
            collectorConfig: $this->collectorConfig,
        );

        // Process the file
        $file = new SplFileInfo($this->filePath);

        return $processor->process($file);
    }
}
