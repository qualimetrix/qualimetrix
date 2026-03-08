<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Aggregator;

use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Core\Metric\MetricDefinition;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Core\Profiler\ProfilerHolder;
use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;

final class MethodToClassAggregator implements AggregationPhaseInterface
{
    /**
     * @param list<MetricDefinition> $definitions
     */
    public function aggregate(InMemoryMetricRepository $repository, array $definitions): void
    {
        $profiler = ProfilerHolder::get();

        $methodDefinitions = array_values(array_filter(
            $definitions,
            static fn(MetricDefinition $d): bool => $d->collectedAt === SymbolLevel::Method
                && $d->hasAggregationsForLevel(SymbolLevel::Class_),
        ));

        if ($methodDefinitions === []) {
            return;
        }

        $profiler->start('aggregation.methods_to_classes.group', 'aggregation');
        $methodsByClass = $this->groupMethodsByClass($repository);
        $profiler->stop('aggregation.methods_to_classes.group');

        $profiler->start('aggregation.methods_to_classes.process', 'aggregation');
        foreach ($methodsByClass as $methodInfos) {
            if ($methodInfos === []) {
                continue;
            }

            $firstInfo = $methodInfos[0];
            $classPath = SymbolPath::forClass(
                $firstInfo->symbolPath->namespace ?? '',
                $firstInfo->symbolPath->type ?? '',
            );

            $metricValues = AggregationHelper::collectMetricValues($repository, $methodInfos, $methodDefinitions);
            $classBag = AggregationHelper::applyAggregations($metricValues, $methodDefinitions, SymbolLevel::Class_);

            // Add WMC alias (WMC = ccn.sum)
            $ccnSum = $classBag->get('ccn.sum');
            if ($ccnSum !== null) {
                $classBag = $classBag->with('wmc', $ccnSum);
            }

            // Add method symbol count for class-level rules (distinct from methodCount quality metric)
            $classBag = $classBag->with('symbolMethodCount', \count($methodInfos));

            $repository->add($classPath, $classBag, $firstInfo->file, 0);
        }
        $profiler->stop('aggregation.methods_to_classes.process');
    }

    /**
     * Groups method symbols by their parent class.
     *
     * @return array<string, list<SymbolInfo>>
     */
    private function groupMethodsByClass(InMemoryMetricRepository $repository): array
    {
        $methodsByClass = [];

        foreach ($repository->all(SymbolType::Method) as $methodInfo) {
            $path = $methodInfo->symbolPath;

            // Skip functions (no class)
            if ($path->type === null) {
                continue;
            }

            $classCanonical = SymbolPath::forClass(
                $path->namespace ?? '',
                $path->type,
            )->toCanonical();

            $methodsByClass[$classCanonical][] = $methodInfo;
        }

        return $methodsByClass;
    }
}
