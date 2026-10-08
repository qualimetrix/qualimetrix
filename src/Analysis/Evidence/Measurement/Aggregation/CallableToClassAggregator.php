<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Aggregation;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;

final class CallableToClassAggregator implements AggregationPhaseInterface
{
    public function __construct(private readonly ProfilerInterface $profiler) {}
    /**
     * @param list<MetricDefinition> $definitions
     */
    public function aggregate(MetricRepositoryInterface $repository, array $definitions): void
    {
        $profiler = $this->profiler;

        $callableDefinitions = array_values(array_filter(
            $definitions,
            static fn(MetricDefinition $d): bool => $d->collectedAt === SymbolLevel::Callable
                && $d->hasAggregationsForLevel(SymbolLevel::Class_),
        ));

        if ($callableDefinitions === []) {
            return;
        }

        $profiler->start('aggregation.callables_to_classes.group', 'aggregation');
        $callablesByClass = $this->groupCallablesByClass($repository);
        $profiler->stop('aggregation.callables_to_classes.group');

        $profiler->start('aggregation.callables_to_classes.process', 'aggregation');
        foreach ($callablesByClass as $callableInfos) {
            if ($callableInfos === []) {
                continue;
            }

            $firstInfo = $callableInfos[0];
            $owner = $firstInfo->classAggregationOwnerDeclaration;
            if ($owner === null) {
                continue;
            }
            $classSubject = MetricSubject::declaration($owner);
            if (!$repository->hasSubject($classSubject)) {
                throw new LogicException('Callable aggregation requires its exact named-owner class declaration');
            }

            $metricValues = $this->collectMetricValues($repository, $callableInfos, $callableDefinitions);
            $classBag = AggregationHelper::applyAggregations($metricValues, $callableDefinitions, SymbolLevel::Class_);

            $classBag = $classBag->with('size.symbol-method-count', \count($callableInfos));

            $repository->addSubject(
                $classSubject,
                $classBag,
                $firstInfo->file,
                0,
            );
        }
        $profiler->stop('aggregation.callables_to_classes.process');
    }

    /**
     * @return array<string, list<SymbolInfo>>
     */
    private function groupCallablesByClass(MetricRepositoryInterface $repository): array
    {
        $callablesByClass = [];

        foreach ($repository->allCallables() as $callableInfo) {
            $owner = self::namedOwner($callableInfo);
            if ($owner === null) {
                continue;
            }

            $classCanonical = $owner->toCanonical();

            $callablesByClass[$classCanonical][] = $callableInfo;
        }

        return $callablesByClass;
    }

    private static function namedOwner(SymbolInfo $callableInfo): ?DeclarationPath
    {
        if ($callableInfo->anonymousClassContext) {
            if ($callableInfo->classAggregationOwner !== null || $callableInfo->classAggregationOwnerDeclaration !== null) {
                throw new LogicException('Anonymous-class callable cannot have a named class owner');
            }

            return null;
        }
        if (\in_array($callableInfo->callableKind, [CallableKind::Method, CallableKind::PropertyHook], true)
            && ($callableInfo->classAggregationOwner === null || $callableInfo->classAggregationOwnerDeclaration === null)) {
            throw new LogicException('Named callable owner requires an exact class declaration');
        }
        if (($callableInfo->classAggregationOwner === null) !== ($callableInfo->classAggregationOwnerDeclaration === null)) {
            throw new LogicException('Incomplete class owner metadata');
        }

        return $callableInfo->classAggregationOwnerDeclaration;
    }

    /**
     * @param list<SymbolInfo> $symbolInfos
     * @param list<MetricDefinition> $definitions
     *
     * @return array<string, list<int|float>>
     */
    private function collectMetricValues(
        MetricRepositoryInterface $repository,
        array $symbolInfos,
        array $definitions,
    ): array {
        $values = [];
        foreach ($definitions as $definition) {
            $values[$definition->name] = [];
        }

        foreach ($symbolInfos as $info) {
            // The denominator below counts every callable of the class, so a
            // callable dropped here would leave the sum and the count measuring
            // different populations. The project's rule for this condition is a
            // refusal, and it has to outlive whatever makes the state
            // unreachable today.
            $subject = $info->subject
                ?? throw new LogicException(\sprintf(
                    'Callable metrics require an exact declaration subject; %s carries none',
                    $info->symbolPath->toString(),
                ));
            $bag = $repository->getSubject($subject);
            foreach ($definitions as $definition) {
                $value = $bag->get($definition->name);
                if ($value !== null) {
                    $values[$definition->name][] = $value;
                }
            }
        }

        return $values;
    }
}
