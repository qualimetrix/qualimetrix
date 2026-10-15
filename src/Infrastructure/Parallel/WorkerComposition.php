<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyTraversalParticipantInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ParallelSafeCollectorInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;
use RuntimeException;

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

    public function assertInstantiable(): void
    {
        self::validateDependencyTraversalParticipantClass($this->dependencyTraversalParticipantClass);
        foreach ([...$this->collectorClasses, ...$this->derivedCollectorClasses] as $class) {
            self::assertCollectorInstantiable($class);
        }
    }

    /** @param class-string $className */
    public static function assertCollectorInstantiable(string $className): void
    {
        if (!class_exists($className)) {
            throw new RuntimeException(\sprintf(
                "WorkerBootstrap: class '%s' does not exist. This indicates a misconfigured collector.",
                $className,
            ));
        }

        if (!is_subclass_of($className, ParallelSafeCollectorInterface::class)) {
            throw new RuntimeException(\sprintf(
                "WorkerBootstrap: collector '%s' does not implement %s and cannot run in a parallel worker. "
                . 'Implement the interface, or run with --workers=0.',
                $className,
                ParallelSafeCollectorInterface::class,
            ));
        }
    }

    /** @return class-string<DependencyTraversalParticipantInterface> */
    public static function validateDependencyTraversalParticipantClass(string $className): string
    {
        if ($className === '') {
            throw new RuntimeException('WorkerBootstrap: dependency traversal participant class must not be empty.');
        }

        if (!class_exists($className)) {
            throw new RuntimeException(\sprintf(
                "WorkerBootstrap: dependency traversal participant class '%s' does not exist.",
                $className,
            ));
        }

        if (!is_subclass_of($className, DependencyTraversalParticipantInterface::class)) {
            throw new RuntimeException(\sprintf(
                "WorkerBootstrap: dependency traversal participant class '%s' must implement %s.",
                $className,
                DependencyTraversalParticipantInterface::class,
            ));
        }

        return $className;
    }
}
