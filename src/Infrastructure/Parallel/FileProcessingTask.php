<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfiguration;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyTraversalParticipantInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricCollectorInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;
use Qualimetrix\Analysis\Run\Collection\FileProcessor;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult;
use Qualimetrix\Core\Path\AbsolutePath;
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
     * @param AbsolutePath $filePath Absolute path to the PHP file to process
     * @param AbsolutePath $projectRoot Project root for autoloading
     * @param list<class-string<MetricCollectorInterface>> $collectorClasses Collector class names
     * @param class-string<DependencyTraversalParticipantInterface> $dependencyTraversalParticipantClass
     * @param list<class-string<DerivedCollectorInterface>> $derivedCollectorClasses Derived collector class names
     * @param AbsolutePath|null $cacheDir Optional cache directory for AST caching
     * @param LcomCollectionConfiguration $lcomConfiguration Exact Cohesion-owned worker configuration
     * @param list<class-string<RuleDefinitionInterface>> $ruleClasses Rule class names (worker rebuilds threshold-override validator map)
     */
    public function __construct(
        private readonly AbsolutePath $filePath,
        private readonly AbsolutePath $projectRoot,
        private readonly array $collectorClasses,
        private readonly string $dependencyTraversalParticipantClass,
        private readonly array $derivedCollectorClasses = [],
        private readonly ?AbsolutePath $cacheDir = null,
        private readonly LcomCollectionConfiguration $lcomConfiguration = new LcomCollectionConfiguration(),
        private readonly array $ruleClasses = [],
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
            dependencyTraversalParticipantClass: $this->dependencyTraversalParticipantClass,
            derivedCollectorClasses: $this->derivedCollectorClasses,
            cacheDir: $this->cacheDir,
            lcomConfiguration: $this->lcomConfiguration,
            ruleClasses: $this->ruleClasses,
        );

        // Process the file
        $file = new SplFileInfo($this->filePath->value());

        return $processor->process($file);
    }
}
