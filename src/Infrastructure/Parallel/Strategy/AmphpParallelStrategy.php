<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel\Strategy;

use Amp\CancelledException;
use Closure;
use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingFailureKind;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult;
use Qualimetrix\Analysis\Run\Contract\Collection\Strategy\ExecutionStrategyInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\Strategy\ParallelCapableInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Infrastructure\Parallel\Contract\WorkerDiagnosticsInterface;
use Qualimetrix\Infrastructure\Parallel\FileProcessingTaskFactory;
use Qualimetrix\Infrastructure\Parallel\WorkerContextFactory;
use Qualimetrix\Infrastructure\Parallel\WorkerNotes;
use Qualimetrix\Infrastructure\Parallel\WorkerPool;
use Qualimetrix\Infrastructure\Parallel\WorkerRequest;
use Qualimetrix\Infrastructure\Parallel\WorkerStartFailure;
use SplFileInfo;
use Throwable;

/**
 * Executes process workers after a bounded bootstrap probe, with in-process
 * fallback below the threshold and for an unavailable automatic request.
 *
 * @qmx-threshold coupling.instability warning=0.857143 -- This execution adapter binds worker, cancellation and collection contracts with few strategy consumers; splitting it transfers the same transport dependencies.
 */
final class AmphpParallelStrategy implements ExecutionStrategyInterface, ParallelCapableInterface
{
    private const int DEFAULT_MIN_FILES_FOR_PARALLEL = 100;
    private const int BATCH_SIZE = 500;
    private WorkerRequest $request;
    private int $minFilesForParallel = self::DEFAULT_MIN_FILES_FOR_PARALLEL;
    private ?AbsolutePath $projectRoot = null;
    private ?AbsolutePath $cacheDir = null;

    public function __construct(private readonly FileProcessingTaskFactory $fileProcessingTaskFactory, private readonly SequentialStrategy $sequentialStrategy, private readonly ProfilerInterface $profiler, private readonly WorkerDiagnosticsInterface $errorStream, private readonly LoggerInterface $logger = new NullLogger())
    {
        $this->request = new WorkerRequest(4);
    }

    public function isParallelAvailable(): bool
    {
        return true;
    }

    public function setWorkerRequest(WorkerRequest $request): void
    {
        $this->request = $request;
    }

    public function setWorkerCount(int $count): void
    {
        $count = max(1, $count);
        $this->request = new WorkerRequest($count, 'parallel.workers=' . $count . ' (programmatic configuration)');
    }

    public function getWorkerCount(): int
    {
        return $this->request->count;
    }

    public function setMinFilesForParallel(int $minFiles): void
    {
        $this->minFilesForParallel = max(1, $minFiles);
    }

    public function setProjectRoot(AbsolutePath $projectRoot): void
    {
        $this->projectRoot = $projectRoot;
    }

    public function setCacheDir(?AbsolutePath $cacheDir): void
    {
        $this->cacheDir = $cacheDir;
    }

    /**
     * @param list<SplFileInfo> $files
     * @param callable(SplFileInfo): mixed $processor Used only for in-process collection.
     *
     * @return list<mixed>
     */
    public function execute(array $files, callable $processor, bool $canParallelize = true): array // @phpstan-ignore method.childReturnType
    {
        if (!$canParallelize || $files === []) {
            return $this->executeSequential($files, $processor);
        }
        if (\count($files) < $this->minFilesForParallel) {
            $this->logger->debug('AmphpParallelStrategy: file count below threshold, using sequential', ['files_count' => \count($files), 'threshold' => $this->minFilesForParallel]);
            return $this->executeSequential($files, $processor);
        }
        if ($this->projectRoot === null) {
            $this->logger->warning('AmphpParallelStrategy: project root not set, using sequential fallback');
            return $this->executeSequential($files, $processor);
        }
        if (!$this->fileProcessingTaskFactory->hasCollectors()) {
            $this->logger->warning('AmphpParallelStrategy: collector classes not configured, using sequential fallback');
            return $this->executeSequential($files, $processor);
        }
        $this->fileProcessingTaskFactory->assertWorkerComposition();
        $capability = WorkerPool::unavailableCapability();
        if ($capability !== null) {
            return $this->startupFallback($files, $processor, WorkerStartFailure::capabilityMissing($capability, $this->request));
        }
        return $this->executeParallel($files, $processor);
    }

    /**
     * @param list<SplFileInfo> $files
     * @param callable(SplFileInfo): mixed $processor
     *
     * @return list<mixed>
     */
    private function startupFallback(array $files, callable $processor, WorkerStartFailure $failure): array
    {
        if ($this->request->explicit()) {
            throw $failure;
        }
        $this->logger->warning('Parallel collection is unavailable; using sequential processing: ' . $failure->getMessage());
        return $this->executeSequential($files, $processor);
    }

    /**
     * @param list<SplFileInfo> $files
     * @param callable(SplFileInfo): mixed $processor
     *
     * @return list<mixed>
     */
    private function executeParallel(array $files, callable $processor): array
    {
        $root = $this->projectRoot ?? throw new LogicException('Parallel collection requires a configured project root.');
        $workerCount = min($this->request->count, \count($files));
        $notes = new WorkerNotes($this->errorStream);
        try {
            $contexts = new WorkerContextFactory($this->request, $notes, logger: $this->logger);
        } catch (WorkerStartFailure $failure) {
            return $this->startupFallback($files, $processor, $failure);
        }
        $pool = WorkerPool::open($workerCount, $this->logger, $contexts, $notes);
        try {
            try {
                $pool->probe($this->fileProcessingTaskFactory->probe($root, $this->cacheDir), $this->request);
            } catch (WorkerStartFailure $failure) {
                $pool->abort();
                return $this->startupFallback($files, $processor, $failure);
            }
            $this->logger->info('AmphpParallelStrategy: starting parallel processing', ['files_count' => \count($files), 'workers' => $workerCount]);
            $this->profiler->start('collection.workers', 'collection', ['files' => \count($files), 'workers' => $workerCount, 'timing' => 'per-file timing not collected in workers']);
            try {
                return $this->processFiles($pool, $files, $processor);
            } finally {
                $this->profiler->stop('collection.workers');
            }
        } catch (CancelledException $failure) {
            $pool->abort();
            throw $failure;
        } finally {
            $pool->close();
        }
    }

    /**
     * @param list<SplFileInfo> $files
     * @param callable(SplFileInfo): mixed $processor
     *
     * @return list<mixed>
     */
    private function processFiles(WorkerPool $pool, array $files, callable $processor): array
    {
        $results = [];
        $batches = array_chunk($files, self::BATCH_SIZE);
        foreach ($batches as $index => $batch) {
            [$collected, $pending, $failure] = $this->processBatch($pool, $batch);
            if ($failure !== null) {
                foreach (\array_slice($batches, $index + 1) as $later) {
                    array_push($pending, ...$later);
                }
                $pool->abort();
                $this->logger->warning(\sprintf('Parallel worker replacement failed; processing %d remaining files sequentially: %s', \count($pending), $failure->getMessage()));
                $fallback = $this->executeSequential($pending, $processor);
                foreach ($fallback as $result) {
                    $collected[] = $result;
                }
                array_push($results, ...$collected);
                return $results;
            }
            array_push($results, ...$collected);
        }
        return $results;
    }

    /**
     * @param list<SplFileInfo> $batch
     *
     * @return array{list<FileProcessingResult>, list<SplFileInfo>, ?WorkerStartFailure}
     */
    private function processBatch(WorkerPool $pool, array $batch): array
    {
        $root = $this->projectRoot ?? throw new LogicException('Parallel collection requires a configured project root.');
        /** @var list<array{file: SplFileInfo, await: Closure(): FileProcessingResult}> $executions */
        $executions = [];
        $pending = [];
        $failure = null;
        foreach ($batch as $index => $file) {
            try {
                $task = $this->fileProcessingTaskFactory->create($this->absolutePath($file), $root, $this->cacheDir);
                $executions[] = ['file' => $file, 'await' => $pool->submit($task)];
            } catch (Throwable $thrown) {
                $failure = $pool->startupFailure($thrown);
                if ($failure === null) {
                    throw $thrown;
                }
                $pending = \array_slice($batch, $index);
                break;
            }
        }
        $results = [];
        foreach ($executions as $item) {
            try {
                $results[] = $item['await']();
            } catch (CancelledException $thrown) {
                throw $thrown;
            } catch (Throwable $thrown) {
                $start = $pool->startupFailure($thrown);
                if ($start !== null) {
                    $failure ??= $start;
                    $pending[] = $item['file'];
                    continue;
                }
                $message = $pool->failureMessage($thrown, $this->absolutePath($item['file'])->value());
                $this->logger->warning('AmphpParallelStrategy: file processing failed', ['file' => $item['file']->getPathname(), 'error' => $message]);
                $results[] = FileProcessingResult::failure($this->relativePathFor($item['file']), $message, FileProcessingFailureKind::Processing);
            }
        }
        return [$results, $pending, $failure];
    }

    /** Resolves the containing directory while retaining the file's written name. */
    private function absolutePath(SplFileInfo $file): AbsolutePath
    {
        $root = $this->projectRoot ?? throw new LogicException('projectRoot must be set before absolutePath');
        $named = PathFactory::fromCliArgument($file->getPathname(), $root);
        $parent = realpath(\dirname($named->value()));
        if ($parent === false) {
            throw new LogicException(\sprintf('Cannot resolve parent of "%s"', $named->value()));
        }

        return AbsolutePath::fromString($parent . '/' . basename($named->value()));
    }

    /** Uses the same written file name for failed and successful tasks. */
    private function relativePathFor(SplFileInfo $file): RelativePath
    {
        if ($this->projectRoot === null) {
            throw new LogicException('projectRoot must be set before relativePathFor');
        }

        return PathFactory::published($this->absolutePath($file), $this->projectRoot);
    }

    /**
     * @param list<SplFileInfo> $files
     * @param callable(SplFileInfo): mixed $processor
     *
     * @return list<mixed>
     */
    private function executeSequential(array $files, callable $processor): array
    {
        return $this->sequentialStrategy->execute($files, $processor, false);
    }
}
