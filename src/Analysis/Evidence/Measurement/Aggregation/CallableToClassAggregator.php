<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Aggregation;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\CallableKind;
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
            $logicalOwner = $firstInfo->classAggregationOwner;
            if ($logicalOwner === null) {
                continue;
            }

            $metricValues = $this->collectMetricValues($repository, $callableInfos, $callableDefinitions);
            $classBag = AggregationHelper::applyAggregations($metricValues, $callableDefinitions, SymbolLevel::Class_);

            $methodValues = $this->collectMetricValues(
                $repository,
                array_values(array_filter(
                    $callableInfos,
                    static fn(SymbolInfo $info): bool => $info->callableKind === CallableKind::Method,
                )),
                $callableDefinitions,
            );
            $methodBag = AggregationHelper::applyAggregations($methodValues, $callableDefinitions, SymbolLevel::Class_);
            $ccnSum = $methodBag->get(MetricName::agg(MetricName::COMPLEXITY_CCN, AggregationStrategy::Sum));
            if ($ccnSum !== null) {
                $classBag = $classBag->with(MetricName::COMPLEXITY_WMC, $ccnSum);
            }

            $classBag = $classBag->with(MetricName::SIZE_SYMBOL_METHOD_COUNT, \count($callableInfos));

            $repository->addSubject(
                MetricSubject::logicalClass($logicalOwner),
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
            $owner = $callableInfo->classAggregationOwner;
            if ($owner === null) {
                continue;
            }

            $classCanonical = $owner->toCanonical();

            $callablesByClass[$classCanonical][] = $callableInfo;
        }

        return $callablesByClass;
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
            $subject = $info->subject;
            if ($subject === null) {
                continue;
            }
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
