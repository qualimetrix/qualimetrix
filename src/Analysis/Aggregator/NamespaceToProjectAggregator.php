<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Aggregator;

use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Core\Namespace_\NamespaceTree;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

final class NamespaceToProjectAggregator implements AggregationPhaseInterface
{
    public function __construct(
        private readonly NamespaceTree $tree,
    ) {}

    /**
     * @param list<MetricDefinition> $definitions
     */
    public function aggregate(MetricRepositoryInterface $repository, array $definitions): void
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

        $projectBag = AggregationHelper::applyAggregations(
            $metricValues,
            $projectDefinitions,
            SymbolLevel::Project,
        );
        // Collect namespace-collected metrics (e.g., distance) from namespace bags.
        // These are stored directly on namespace SymbolPaths, not on class/file symbols.
        $namespaceCollectedDefs = array_values(array_filter(
            $projectDefinitions,
            static fn(MetricDefinition $d): bool => $d->collectedAt === SymbolLevel::Namespace_,
        ));

        if ($namespaceCollectedDefs !== []) {
            $nsValues = [];

            foreach ($namespaceCollectedDefs as $def) {
                $nsValues[$def->name] = [];
            }

            // Only aggregate leaf namespaces (those with class/method/function symbols)
            // to avoid double-counting parent namespaces whose I/A/D are derived from children.
            $leafNamespaces = $this->tree->getLeaves();

            foreach ($leafNamespaces as $namespace) {
                $nsBag = $repository->get(SymbolPath::forNamespace($namespace));

                foreach ($namespaceCollectedDefs as $def) {
                    $value = $nsBag->get($def->name);

                    if ($value !== null) {
                        $nsValues[$def->name][] = $value;
                    }
                }
            }

            $nsBag = AggregationHelper::applyAggregations(
                $nsValues,
                $namespaceCollectedDefs,
                SymbolLevel::Project,
            );
            $projectBag = $projectBag->merge($nsBag);
        }

        $projectBag = AggregationHelper::addSymbolCounts($projectBag, $allSymbolInfos);

        $firstFile = $allSymbolInfos[0]->file;
        $projectPath = SymbolPath::forProject();
        $repository->add($projectPath, $projectBag, $firstFile, null);
        $profiler->stop('aggregation.to_project.process');
    }

}
