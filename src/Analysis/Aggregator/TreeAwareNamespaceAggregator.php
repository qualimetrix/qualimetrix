<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Aggregator;

use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Core\Namespace_\NamespaceTree;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Aggregates metrics from leaf namespaces into parent namespaces.
 *
 * Uses NamespaceTree to discover parent namespaces and their descendant leaves.
 * Aggregates from raw class/function/file symbols (not from child namespace bags)
 * to produce mathematically correct .avg and .p95 values.
 *
 * Replaces NamespaceHierarchyAggregator which had hardcoded SUM_METRICS.
 *
 * Must run AFTER ClassToNamespaceAggregator, BEFORE NamespaceToProjectAggregator.
 */
final class TreeAwareNamespaceAggregator implements AggregationPhaseInterface
{
    public function __construct(
        private readonly NamespaceTree $tree,
    ) {}

    /**
     * @param list<MetricDefinition> $definitions
     */
    public function aggregate(MetricRepositoryInterface $repository, array $definitions): void
    {
        $namespaceDefinitions = array_values(array_filter(
            $definitions,
            static fn(MetricDefinition $d): bool => $d->hasAggregationsForLevel(SymbolLevel::Namespace_),
        ));

        $parentNamespaces = $this->tree->getParentNamespaces();

        if ($parentNamespaces === []) {
            return;
        }

        // Sort by depth descending (deeper parents first).
        // With leaf-input aggregation order doesn't affect correctness,
        // but processing deeper parents first ensures consistent behavior.
        usort($parentNamespaces, static fn(string $a, string $b): int => substr_count($b, '\\') <=> substr_count($a, '\\'));

        // Build maps once before the loop
        $fileToNamespace = AggregationHelper::buildFileToNamespaceMap($repository);
        $fileSymbolsMap = AggregationHelper::buildNamespaceToFileSymbolsMap($repository, $fileToNamespace);

        foreach ($parentNamespaces as $parentNs) {
            // Collect from ALL namespaces in the subtree (leaves + intermediate parents
            // with own symbols). This ensures correct .avg/.p95 from raw symbols.
            $namespacesToCollect = $this->tree->getDescendants($parentNs);

            // Include the parent itself if it has own symbols (leaf-and-parent case)
            $namespacesToCollect[] = $parentNs;

            $allSymbolInfos = [];
            $allFileSymbols = [];

            foreach ($namespacesToCollect as $ns) {
                foreach ($repository->forNamespace($ns) as $info) {
                    $allSymbolInfos[] = $info;
                }

                foreach ($fileSymbolsMap[$ns] ?? [] as $fileSymbol) {
                    $allFileSymbols[] = $fileSymbol;
                }
            }

            if ($allSymbolInfos === [] && $allFileSymbols === []) {
                continue;
            }

            $aggregationValues = AggregationHelper::collectNamespaceMetricValues(
                $repository,
                $allSymbolInfos,
                $allFileSymbols,
                $namespaceDefinitions,
            );

            $bag = AggregationHelper::applyAggregations(
                $aggregationValues->values,
                $namespaceDefinitions,
                SymbolLevel::Namespace_,
                $aggregationValues->weights,
            );
            $bag = AggregationHelper::addSymbolCounts($bag, $allSymbolInfos);

            $firstFile = $this->findFirstFile($allSymbolInfos);
            $parentPath = SymbolPath::forNamespace($parentNs);
            $repository->add($parentPath, $bag, $firstFile, null);
        }
    }

    /**
     * @param list<SymbolInfo> $symbolInfos
     */
    private function findFirstFile(array $symbolInfos): string
    {
        foreach ($symbolInfos as $info) {
            return $info->file;
        }

        return '';
    }
}
