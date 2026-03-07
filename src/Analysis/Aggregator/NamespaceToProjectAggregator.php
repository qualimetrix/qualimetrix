<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Aggregator;

use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Core\Metric\MetricDefinition;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Core\Profiler\ProfilerHolder;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\SymbolPath;

final class NamespaceToProjectAggregator implements AggregationPhaseInterface
{
    /**
     * @param list<MetricDefinition> $definitions
     */
    public function aggregate(InMemoryMetricRepository $repository, array $definitions): void
    {
        $profiler = ProfilerHolder::get();

        $projectDefinitions = array_values(array_filter(
            $definitions,
            static fn(MetricDefinition $d): bool => $d->hasAggregationsForLevel(SymbolLevel::Project),
        ));

        if ($projectDefinitions === []) {
            return;
        }

        $profiler->start('aggregation.to_project.collect_symbols', 'aggregation');
        $allSymbolInfos = [];

        foreach ($repository->getNamespaces() as $namespace) {
            foreach ($repository->forNamespace($namespace) as $info) {
                $allSymbolInfos[] = $info;
            }
        }

        if ($allSymbolInfos === []) {
            $profiler->stop('aggregation.to_project.collect_symbols');
            return;
        }

        $allFileSymbols = array_values(iterator_to_array($repository->all(SymbolType::File)));
        $profiler->stop('aggregation.to_project.collect_symbols');

        $profiler->start('aggregation.to_project.process', 'aggregation');
        $metricValues = AggregationHelper::collectNamespaceMetricValues(
            $repository,
            $allSymbolInfos,
            $allFileSymbols,
            $projectDefinitions,
        );

        $projectBag = AggregationHelper::applyAggregations($metricValues, $projectDefinitions, SymbolLevel::Project);
        $projectBag = AggregationHelper::addSymbolCounts($projectBag, $allSymbolInfos);

        $firstFile = $allSymbolInfos[0]->file;
        $projectPath = SymbolPath::forNamespace('');
        $repository->add($projectPath, $projectBag, $firstFile, null);
        $profiler->stop('aggregation.to_project.process');
    }
}
