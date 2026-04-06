<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel\Strategy;

use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\Execution;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Collection\FileProcessingResult;
use Qualimetrix\Analysis\Collection\Strategy\ExecutionStrategyInterface;
use Qualimetrix\Analysis\Collection\Strategy\ParallelCapableInterface;
use Qualimetrix\Core\Metric\DerivedCollectorInterface;
use Qualimetrix\Core\Metric\MetricCollectorInterface;
use Qualimetrix\Infrastructure\Parallel\FileProcessingTask;
use SplFileInfo;
use Throwable;

/**
 * Parallel execution strategy using amphp/parallel.
 *
 * Uses amphp/parallel's worker pool for true parallel processing.
 * Automatically selects best transport (ext-parallel threads or pcntl fork).
 *
 * Key features:
 * - Worker pool with configurable worker count
 * - Automatic fallback to sequential for small file sets
 * - Configurable minimum files threshold for parallelization
 * - AST caching support in workers
 * - Collector classes synchronized with DI container
 *
 * @see https://github.com/amphp/parallel
 */
final class AmphpParallelStrategy implements ExecutionStrategyInterface, ParallelCapableInterface
{
    /**
     * Default minimum number of files to enable parallelization.
     * For smaller file sets, sequential processing is more efficient.
     *
     * Based on benchmarks:
     * - <100 files: parallel is slower due to worker spawn overhead
     * - ~150 files: roughly equal performance
     * - >200 files: parallel provides significant speedup
     */
    private const int DEFAULT_MIN_FILES_FOR_PARALLEL = 100;

    /**
     * Batch size for processing files in chunks.
     * Files are processed in batches to limit memory usage.
     * Each batch submits all tasks at once (efficient) while keeping total
     * memory bounded.
     */
    private const int BATCH_SIZE = 500;

    private int $workerCount = 4;
    private int $minFilesForParallel = self::DEFAULT_MIN_FILES_FOR_PARALLEL;
    private ?string $projectRoot = null;
    private ?string $cacheDir = null;

    /** @var list<class-string<MetricCollectorInterface>> */
    private array $collectorClasses = [];

    /** @var list<class-string<DerivedCollectorInterface>> */
    private array $derivedCollectorClasses = [];

    /** @var array<string, mixed> */
    private array $collectorConfig = [];

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function isAvailable(): bool
    {
        // Check if amphp/parallel is installed
        if (!class_exists(ContextWorkerPool::class)) {
            return false;
        }

        // amphp/parallel requires either ext-parallel or pcntl
        return \extension_loaded('parallel') || \function_exists('pcntl_fork');
    }

    public function isParallelAvailable(): bool
    {
        return $this->isAvailable();
    }

    public function setWorkerCount(int $count): void
    {
        $this->workerCount = max(1, $count);
    }

    public function getWorkerCount(): int
    {
        return $this->workerCount;
    }

    /**
     * Sets the minimum number of files required to enable parallelization.
     *
     * @param int $minFiles Minimum file count (default: 10)
     */
    public function setMinFilesForParallel(int $minFiles): void
    {
        $this->minFilesForParallel = max(1, $minFiles);
    }

    /**
     * Sets the project root directory (required for worker bootstrap).
     *
     * @param string $projectRoot Absolute path to project root
     */
    public function setProjectRoot(string $projectRoot): void
    {
        $this->projectRoot = $projectRoot;
    }

    /**
     * Sets the cache directory for AST caching in workers.
     *
     * @param string|null $cacheDir Cache directory or null to disable caching
     */
    public function setCacheDir(?string $cacheDir): void
    {
        $this->cacheDir = $cacheDir;
    }

    /**
     * Sets the collector class names from DI container.
     *
     * These classes will be instantiated in worker processes to ensure
     * the same set of collectors is used as in the main process.
     *
     * @param list<class-string<MetricCollectorInterface>> $classes Collector class names
     */
    public function setCollectorClasses(array $classes): void
    {
        $this->collectorClasses = $classes;
    }

    /**
     * Sets the derived collector class names from DI container.
     *
     * @param list<class-string<DerivedCollectorInterface>> $classes Derived collector class names
     */
    public function setDerivedCollectorClasses(array $classes): void
    {
        $this->derivedCollectorClasses = $classes;
    }

    /**
     * Sets collector-level configuration to pass to worker processes.
     *
     * @param array<string, mixed> $config Key-value pairs (e.g., LCOM exclude methods)
     */
    public function setCollectorConfig(array $config): void
    {
        $this->collectorConfig = $config;
    }

    /**
     * Execute processing for files.
     *
     * NOTE: In parallel mode, the $processor callable is NOT used. Instead, files are
     * processed by FileProcessingTask in worker processes using WorkerBootstrap.
     * The $processor is only used for sequential fallback scenarios (small file count,
     * missing project root, parallel not available, etc.).
     *
     * This design choice is intentional: closures cannot be serialized to worker processes,
     * and we need a consistent, pre-configured collector pipeline in each worker.
     *
     * @param list<SplFileInfo> $files
     * @param callable(SplFileInfo): mixed $processor Processor function (used only in sequential mode)
     * @param bool $canParallelize Whether parallel execution is allowed (default: true)
     *
     * @return list<mixed>
     */
    public function execute(array $files, callable $processor, bool $canParallelize = true): array // @phpstan-ignore method.childReturnType
    {
        // Fallback to sequential if parallelization is not possible
        if (!$canParallelize || $files === []) {
            return $this->executeSequential($files, $processor);
        }

        // Check minimum files threshold
        if (\count($files) < $this->minFilesForParallel) {
            $this->logger->debug(
                'AmphpParallelStrategy: file count below threshold, using sequential',
                ['files_count' => \count($files), 'threshold' => $this->minFilesForParallel],
            );

            return $this->executeSequential($files, $processor);
        }

        // Check if project root is set
        if ($this->projectRoot === null) {
            $this->logger->warning(
                'AmphpParallelStrategy: project root not set, using sequential fallback',
            );

            return $this->executeSequential($files, $processor);
        }

        // Check if collector classes are configured
        if ($this->collectorClasses === []) {
            $this->logger->warning(
                'AmphpParallelStrategy: collector classes not configured, using sequential fallback',
            );

            return $this->executeSequential($files, $processor);
        }

        // Check if parallel processing is available
        if (!$this->isAvailable()) {
            $this->logger->info(
                'AmphpParallelStrategy: parallel not available, using sequential fallback',
            );

            return $this->executeSequential($files, $processor);
        }

        return $this->executeParallel($files);
    }

    /**
     * Executes files in parallel using amphp/parallel worker pool.
     *
     * Uses batch processing to balance efficiency and memory usage:
     * - Files are split into batches of BATCH_SIZE
     * - Each batch is processed fully (all tasks submitted, all results awaited)
     * - This prevents OOM while minimizing task submission overhead
     *
     * Uses graceful error handling: if individual files fail, they are recorded
     * as FileProcessingResult::failure() rather than failing the entire batch.
     * This ensures partial results are not lost due to a single file error.
     *
     * @param list<SplFileInfo> $files
     *
     * @return list<mixed> Returns list of FileProcessingResult (success or failure for each file)
     */
    private function executeParallel(array $files): array
    {
        // Assertion: projectRoot must be set (checked in execute() before calling this method)
        \assert($this->projectRoot !== null, 'projectRoot must be set before calling executeParallel');

        $totalFiles = \count($files);
        $batchCount = (int) ceil($totalFiles / self::BATCH_SIZE);

        $this->logger->info(
            'AmphpParallelStrategy: starting parallel processing',
            [
                'files_count' => $totalFiles,
                'workers' => $this->workerCount,
                'batch_size' => self::BATCH_SIZE,
                'batch_count' => $batchCount,
                'collectors' => \count($this->collectorClasses),
                'derived_collectors' => \count($this->derivedCollectorClasses),
            ],
        );

        // Create worker pool
        $pool = new ContextWorkerPool($this->workerCount);

        try {
            $results = [];
            $errorCount = 0;

            // Process files in batches
            $batches = array_chunk($files, self::BATCH_SIZE);
            foreach ($batches as $batchIndex => $batch) {
                $batchResults = $this->processBatch($pool, $batch, $batchIndex, $batchCount, $errorCount);
                array_push($results, ...$batchResults);
            }

            if ($errorCount > 0) {
                $this->logger->warning(
                    'AmphpParallelStrategy: parallel processing completed with errors',
                    [
                        'total_files' => $totalFiles,
                        'failed_files' => $errorCount,
                    ],
                );
            }

            return $results;
        } catch (Throwable $e) {
            // This catches errors in task submission, not execution
            $this->logger->error(
                'AmphpParallelStrategy: parallel processing failed',
                ['error' => $e->getMessage()],
            );

            throw $e;
        } finally {
            // Always shutdown the pool
            $pool->shutdown();
        }
    }

    /**
     * Processes a single batch of files.
     *
     * @param list<SplFileInfo> $batch
     * @param int &$errorCount Reference to error counter
     *
     * @return list<FileProcessingResult>
     */
    private function processBatch(
        ContextWorkerPool $pool,
        array $batch,
        int $batchIndex,
        int $totalBatches,
        int &$errorCount,
    ): array {
        $this->logger->debug(
            'AmphpParallelStrategy: processing batch',
            ['batch' => $batchIndex + 1, 'of' => $totalBatches, 'files' => \count($batch)],
        );

        // Assertion: projectRoot is guaranteed non-null here (checked in executeParallel)
        \assert($this->projectRoot !== null);

        // Submit all tasks in batch
        /** @var list<array{file: SplFileInfo, execution: Execution<FileProcessingResult, mixed, mixed>}> $executions */
        $executions = [];
        foreach ($batch as $file) {
            $task = new FileProcessingTask(
                filePath: $file->getPathname(),
                projectRoot: $this->projectRoot,
                collectorClasses: $this->collectorClasses,
                derivedCollectorClasses: $this->derivedCollectorClasses,
                cacheDir: $this->cacheDir,
                collectorConfig: $this->collectorConfig,
            );
            $executions[] = [
                'file' => $file,
                'execution' => $pool->submit($task),
            ];
        }

        // Await all results
        $results = [];
        foreach ($executions as $item) {
            $file = $item['file'];
            $execution = $item['execution'];

            try {
                /** @var FileProcessingResult $result */
                $result = $execution->getFuture()->await();
                $results[] = $result;
            } catch (Throwable $e) {
                // Record failure for this specific file, continue processing others
                $errorCount++;
                $this->logger->warning(
                    'AmphpParallelStrategy: file processing failed',
                    [
                        'file' => $file->getPathname(),
                        'error' => $e->getMessage(),
                    ],
                );
                $results[] = FileProcessingResult::failure(
                    $file->getPathname(),
                    $e->getMessage(),
                );
            }
        }

        return $results;
    }

    /**
     * Executes files sequentially (fallback).
     *
     * @param list<SplFileInfo> $files
     * @param callable(SplFileInfo): mixed $processor
     *
     * @return list<mixed>
     */
    private function executeSequential(array $files, callable $processor): array
    {
        $results = [];

        foreach ($files as $file) {
            $results[] = $processor($file);
        }

        return $results;
    }
}
