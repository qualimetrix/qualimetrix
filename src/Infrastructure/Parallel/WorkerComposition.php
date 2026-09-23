<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyTraversalParticipantInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricCollectorInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;

/**
 * The classes a worker rebuilds its file processor from: the same collectors,
 * traversal participant and rules the container registered, carried by name
 * because a worker process has no container.
 *
 * @internal
 */
final readonly class WorkerComposition
{
    /**
     * @param list<class-string<MetricCollectorInterface>> $collectorClasses
     * @param class-string<DependencyTraversalParticipantInterface> $dependencyTraversalParticipantClass
     * @param list<class-string<DerivedCollectorInterface>> $derivedCollectorClasses
     * @param list<class-string<RuleDefinitionInterface>> $ruleClasses the worker rebuilds the threshold-override validator map from them
     */
    public function __construct(
        public array $collectorClasses,
        public string $dependencyTraversalParticipantClass,
        public array $derivedCollectorClasses = [],
        public array $ruleClasses = [],
    ) {}
}
