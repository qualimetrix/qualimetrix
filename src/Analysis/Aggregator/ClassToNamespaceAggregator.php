<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Aggregator;

use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Core\Symbol\SymbolPath;

final class ClassToNamespaceAggregator implements AggregationPhaseInterface
{
    /**
     * @param list<MetricDefinition> $definitions
     */
    public function aggregate(MetricRepositoryInterface $repository, array $definitions): void
    {
        $profiler = ProfilerHolder::get();

        $namespaceDefinitions = array_values(array_filter(
            $definitions,
            static fn(MetricDefinition $d): bool => $d->hasAggregationsForLevel(SymbolLevel::Namespace_),
        ));

        if ($namespaceDefinitions === []) {
            return;
        }

        $profiler->start('aggregation.to_namespaces.build_map', 'aggregation');
        $fileToNamespace = NamespaceMetricContributions::mapFilesToNamespaces($repository);
        $namespaceToFileSymbols = NamespaceMetricContributions::mapNamespacesToFileSymbols($repository, $fileToNamespace);
        $profiler->stop('aggregation.to_namespaces.build_map');

        $profiler->start('aggregation.to_namespaces.process', 'aggregation');
        foreach ($repository->getNamespaces() as $namespace) {
            $symbolInfos = $repository->forNamespace($namespace);

            if ($symbolInfos === []) {
                continue;
            }

            $fileSymbols = $namespaceToFileSymbols[$namespace] ?? [];

            $metricValues = NamespaceMetricContributions::collectValues(
                $repository,
                $symbolInfos,
                $fileSymbols,
                $namespaceDefinitions,
                SymbolLevel::Namespace_,
            );

            $namespaceBag = AggregationHelper::applyAggregations(
                $metricValues,
                $namespaceDefinitions,
                SymbolLevel::Namespace_,
            );
            $namespaceBag = AggregationHelper::addSymbolCounts($namespaceBag, $symbolInfos);

            $firstFile = $symbolInfos[0]->file;
            $namespacePath = SymbolPath::forNamespace($namespace);
            $repository->add($namespacePath, $namespaceBag, $firstFile, null);
        }
        $profiler->stop('aggregation.to_namespaces.process');
    }

}
