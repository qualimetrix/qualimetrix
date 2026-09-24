<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfiguration;
use Qualimetrix\Analysis\Run\Collection\FileProcessor;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult;
use Qualimetrix\Core\Path\AbsolutePath;
use RuntimeException;
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
 * @qmx-threshold coupling.instability warning=0.81 -- A task is efferent by construction:
 * it names the worker-side machinery it runs, and only its factory and the pool name it
 * back. Ca=2, Ce=8 is exactly 0.800 against an inclusive 0.800 ceiling; the eighth
 * efferent edge is `WorkerComposition`, which took four constructor parameters.
 *
 * @implements Task<FileProcessingResult, mixed, mixed>
 */
final class FileProcessingTask implements Task
{
    /**
     * @param AbsolutePath $filePath Absolute path to the PHP file to process
     * @param AbsolutePath $projectRoot Project root for autoloading
     * @param string $memoryLimit The coordinator's `memory_limit`. A worker is a separate process
     *                            that starts under `php.ini`, and `ini_set()` does not cross a
     *                            process boundary, so the task carries the value the run resolved
     * @param AbsolutePath|null $cacheDir Optional cache directory for AST caching
     * @param LcomCollectionConfiguration $lcomConfiguration Exact Cohesion-owned worker configuration
     */
    public function __construct(
        private readonly AbsolutePath $filePath,
        private readonly AbsolutePath $projectRoot,
        private readonly WorkerComposition $composition,
        private readonly string $memoryLimit,
        private readonly ?AbsolutePath $cacheDir = null,
        private readonly LcomCollectionConfiguration $lcomConfiguration = new LcomCollectionConfiguration(),
    ) {}

    /**
     * Executes the task in the worker process.
     *
     * @param Channel<mixed, mixed> $channel Communication channel (unused)
     * @param Cancellation $cancellation Cancellation token for graceful shutdown
     *
     * @throws \Amp\CancelledException If cancellation was requested before processing started
     * @throws RuntimeException If the worker's runtime refuses the carried `memory_limit`
     *
     * @return FileProcessingResult The result of processing the file
     */
    public function run(Channel $channel, Cancellation $cancellation): FileProcessingResult
    {
        // Check for cancellation before starting work
        $cancellation->throwIfRequested();

        $this->applyMemoryLimit();

        // Get or create FileProcessor via WorkerBootstrap
        // WorkerBootstrap caches the processor for reuse across tasks in the same worker
        $processor = WorkerBootstrap::getFileProcessor(
            projectRoot: $this->projectRoot,
            collectorClasses: $this->composition->collectorClasses,
            dependencyTraversalParticipantClass: $this->composition->dependencyTraversalParticipantClass,
            derivedCollectorClasses: $this->composition->derivedCollectorClasses,
            cacheDir: $this->cacheDir,
            lcomConfiguration: $this->lcomConfiguration,
            ruleClasses: $this->composition->ruleClasses,
        );

        // Process the file
        $file = new SplFileInfo($this->filePath->value());

        return $processor->process($file);
    }

    private function applyMemoryLimit(): void
    {
        if (\ini_get('memory_limit') === $this->memoryLimit) {
            return;
        }

        // A limit below what the worker already uses is refused with a warning
        // as well as `false`; the `false` is what this method answers with.
        set_error_handler(static fn(): bool => true);
        try {
            $applied = ini_set('memory_limit', $this->memoryLimit);
        } finally {
            restore_error_handler();
        }

        if ($applied === false) {
            throw new RuntimeException(\sprintf(
                'The worker process could not apply memory_limit "%s" that the run resolved.',
                $this->memoryLimit,
            ));
        }
    }
}
