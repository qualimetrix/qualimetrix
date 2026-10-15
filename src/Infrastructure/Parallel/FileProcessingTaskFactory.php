<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel;

use InvalidArgumentException;
use LogicException;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfigurationStoreInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyTraversalParticipantInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricCollectorInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionDocumentFormsInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use RuntimeException;

/** Creates serializable tasks with compile-time metadata and current runtime configuration. */
final readonly class FileProcessingTaskFactory
{
    /**
     * @param class-string<DependencyTraversalParticipantInterface> $dependencyTraversalParticipantClass
     * @param list<class-string<MetricCollectorInterface>> $collectorClasses
     * @param list<class-string<DerivedCollectorInterface>> $derivedCollectorClasses
     * @param list<class-string<RuleDefinitionInterface>> $ruleClasses
     */
    public function __construct(
        private LcomCollectionConfigurationStoreInterface $lcomConfigurationStore,
        private RuleOptionDocumentFormsInterface $documentForms,
        private string $dependencyTraversalParticipantClass,
        private array $collectorClasses = [],
        private array $derivedCollectorClasses = [],
        private array $ruleClasses = [],
    ) {
        if (!class_exists($dependencyTraversalParticipantClass)
            || !is_subclass_of($dependencyTraversalParticipantClass, DependencyTraversalParticipantInterface::class)
        ) {
            throw new InvalidArgumentException(\sprintf(
                'Dependency traversal participant class "%s" must implement %s.',
                $dependencyTraversalParticipantClass,
                DependencyTraversalParticipantInterface::class,
            ));
        }
    }

    public function create(
        AbsolutePath $filePath,
        AbsolutePath $projectRoot,
        ?AbsolutePath $cacheDir,
    ): FileProcessingTask {
        return new FileProcessingTask(
            filePath: $filePath,
            projectRoot: $projectRoot,
            composition: new WorkerComposition(
                collectorClasses: $this->collectorClasses,
                dependencyTraversalParticipantClass: $this->dependencyTraversalParticipantClass,
                derivedCollectorClasses: $this->derivedCollectorClasses,
                ruleClasses: $this->ruleClasses,
            ),
            documentForms: $this->documentForms,
            // Read per task rather than once: the coordinator applies the
            // run's limit after the container that built this factory.
            memoryLimit: (string) \ini_get('memory_limit'),
            cacheDir: $cacheDir,
            lcomConfiguration: $this->lcomConfigurationStore->current(),
        );
    }

    public function composition(): WorkerComposition
    {
        return new WorkerComposition($this->collectorClasses, $this->dependencyTraversalParticipantClass, $this->derivedCollectorClasses, $this->ruleClasses);
    }

    public function assertWorkerComposition(): void
    {
        try {
            $this->composition()->assertInstantiable();
        } catch (RuntimeException $failure) {
            throw new LogicException($failure->getMessage(), 0, $failure);
        }
    }

    public function probe(AbsolutePath $projectRoot, ?AbsolutePath $cacheDir): WorkerProbeTask
    {
        return new WorkerProbeTask($projectRoot, $this->composition(), $this->documentForms, $cacheDir, $this->lcomConfigurationStore->current());
    }

    public function hasCollectors(): bool
    {
        return $this->collectorClasses !== [];
    }
}
