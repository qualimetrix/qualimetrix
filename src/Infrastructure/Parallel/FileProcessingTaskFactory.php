<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyTraversalParticipantInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CollectorRuntimeConfigurationStoreInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricCollectorInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;
use Qualimetrix\Core\Path\AbsolutePath;

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
        private CollectorRuntimeConfigurationStoreInterface $collectorRuntimeConfigurationStore,
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
            collectorClasses: $this->collectorClasses,
            dependencyTraversalParticipantClass: $this->dependencyTraversalParticipantClass,
            derivedCollectorClasses: $this->derivedCollectorClasses,
            cacheDir: $cacheDir,
            collectorConfig: $this->collectorRuntimeConfigurationStore->current()->toPayload(),
            ruleClasses: $this->ruleClasses,
        );
    }

    public function hasCollectors(): bool
    {
        return $this->collectorClasses !== [];
    }
}
