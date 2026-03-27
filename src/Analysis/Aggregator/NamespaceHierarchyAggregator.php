<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Aggregator;

use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Aggregates leaf-namespace metrics into parent namespaces.
 *
 * Parent namespaces (e.g., "App\Core") that contain only sub-namespaces
 * (no direct classes) are not created by ClassToNamespaceAggregator.
 * This phase discovers them and sums up count/size metrics from descendant
 * leaf namespaces, so that GlobalContextCollectors (CouplingCollector,
 * AbstractnessCollector, DistanceCollector) can compute I/A/D for them.
 *
 * Must run AFTER ClassToNamespaceAggregator, BEFORE NamespaceToProjectAggregator.
 */
final class NamespaceHierarchyAggregator implements AggregationPhaseInterface
{
    /**
     * Metrics that are summed from leaf namespaces to parent namespaces.
     * These are the ".sum" variants created by ClassToNamespaceAggregator.
     */
    private const array SUM_METRICS = [
        MetricName::SIZE_CLASS_COUNT . '.sum',
        MetricName::SIZE_ABSTRACT_CLASS_COUNT . '.sum',
        MetricName::SIZE_INTERFACE_COUNT . '.sum',
        MetricName::SIZE_TRAIT_COUNT . '.sum',
        MetricName::SIZE_ENUM_COUNT . '.sum',
        MetricName::SIZE_LOC . '.sum',
        'symbolMethodCount',
        'symbolClassCount',
    ];

    /**
     * @param list<MetricDefinition> $definitions
     */
    public function aggregate(MetricRepositoryInterface $repository, array $definitions): void
    {
        $leafNamespaces = $repository->getNamespaces();

        if ($leafNamespaces === []) {
            return;
        }

        // Discover parent namespaces that are not already leaf namespaces
        $leafSet = array_fill_keys($leafNamespaces, true);
        $parentToLeaves = $this->buildParentToLeavesMap($leafNamespaces, $leafSet);

        if ($parentToLeaves === []) {
            return;
        }

        // Process bottom-up: deeper parents first, so that shallower parents
        // can include already-aggregated intermediate parents.
        // Sort by depth (number of backslashes) descending.
        uksort($parentToLeaves, static fn(string $a, string $b): int => substr_count($b, '\\') <=> substr_count($a, '\\'));

        foreach ($parentToLeaves as $parentNs => $childNamespaces) {
            $parentPath = SymbolPath::forNamespace($parentNs);

            // If parent is also a leaf namespace (has direct classes),
            // include its own metrics in the sum to avoid losing them on merge.
            $isAlsoLeaf = isset($leafSet[$parentNs]);

            if ($isAlsoLeaf) {
                $childNamespaces[] = $parentNs;
            }

            $bag = $this->sumChildMetrics($repository, $childNamespaces);

            // Pick the first file from the first child namespace for reference
            $firstFile = $this->findFirstFile($repository, $childNamespaces);

            // add() merges: for leaf-and-parent case, the summed bag overwrites
            // existing metrics with the correct totals (own + children).
            $repository->add($parentPath, $bag, $firstFile, null);
        }
    }

    /**
     * Builds a map of parent namespace => list of direct child namespaces.
     *
     * Only includes parents that are NOT already leaf namespaces (unless
     * they have additional children beyond themselves).
     *
     * @param list<string> $leafNamespaces
     * @param array<string, true> $leafSet
     *
     * @return array<string, list<string>> parent => direct children (leaf or already-aggregated parent)
     */
    private function buildParentToLeavesMap(array $leafNamespaces, array $leafSet): array
    {
        /** @var array<string, list<string>> $parentToChildren */
        $parentToChildren = [];
        $allKnown = $leafSet;

        foreach ($leafNamespaces as $ns) {
            $lastSlash = strrpos($ns, '\\');

            while ($lastSlash !== false) {
                $parentNs = substr($ns, 0, $lastSlash);

                $parentToChildren[$parentNs][] = $ns;

                // If parent is already a known namespace (leaf or discovered parent), stop
                if (isset($allKnown[$parentNs])) {
                    break;
                }

                $allKnown[$parentNs] = true;
                // Continue up: this parent also needs its own parent
                $ns = $parentNs;
                $lastSlash = strrpos($ns, '\\');
            }
        }

        return $parentToChildren;
    }

    /**
     * Sums metrics from child namespace bags into a parent bag.
     *
     * @param list<string> $childNamespaces
     */
    private function sumChildMetrics(MetricRepositoryInterface $repository, array $childNamespaces): MetricBag
    {
        $sums = array_fill_keys(self::SUM_METRICS, 0.0);

        foreach ($childNamespaces as $childNs) {
            $childPath = SymbolPath::forNamespace($childNs);
            $childBag = $repository->get($childPath);

            foreach (self::SUM_METRICS as $metric) {
                $value = $childBag->get($metric);

                if ($value !== null) {
                    $sums[$metric] += $value;
                }
            }
        }

        $bag = new MetricBag();

        foreach ($sums as $metric => $value) {
            $bag = $bag->with($metric, $value);
        }

        return $bag;
    }

    /**
     * Finds the first file from the first child namespace that has symbols.
     *
     * @param list<string> $childNamespaces
     */
    private function findFirstFile(MetricRepositoryInterface $repository, array $childNamespaces): string
    {
        foreach ($childNamespaces as $childNs) {
            $symbols = $repository->forNamespace($childNs);

            foreach ($symbols as $symbol) {
                return $symbol->file;
            }
        }

        return '';
    }
}
