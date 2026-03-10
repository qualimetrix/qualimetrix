<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Aggregator;

use AiMessDetector\Analysis\Exception\CyclicDependencyException;
use AiMessDetector\Core\Metric\GlobalContextCollectorInterface;

/**
 * Sorts GlobalContextCollectors in topological order based on their dependencies.
 *
 * Uses Kahn's algorithm to ensure collectors are executed in an order where
 * each collector's required metrics are already computed by previous collectors.
 */
final class GlobalCollectorSorter
{
    /**
     * Sorts collectors in topological order based on requires() → provides() dependencies.
     *
     * @param iterable<GlobalContextCollectorInterface> $collectors
     *
     * @throws CyclicDependencyException When a cyclic dependency is detected
     *
     * @return list<GlobalContextCollectorInterface>
     */
    public function sort(iterable $collectors): array
    {
        $collectorList = $this->toList($collectors);

        if (\count($collectorList) <= 1) {
            return $collectorList;
        }

        // Build mapping: metric → collector that provides it
        $providers = $this->buildProvidersMap($collectorList);

        // Build collector → index mapping for O(1) lookup
        $indexByName = [];
        foreach ($collectorList as $index => $collector) {
            $indexByName[$collector->getName()] = $index;
        }

        // Calculate in-degree for each collector (number of unsatisfied dependencies)
        // and build dependency graph (collector → list of dependent collectors)
        $inDegree = array_fill(0, \count($collectorList), 0);
        $dependents = array_fill(0, \count($collectorList), []);

        foreach ($collectorList as $index => $collector) {
            $requiredProviders = $this->findRequiredProviders($collector, $providers);

            foreach ($requiredProviders as $providerName) {
                if (isset($indexByName[$providerName])) {
                    $providerIndex = $indexByName[$providerName];
                    // This collector depends on $providerIndex
                    $inDegree[$index]++;
                    $dependents[$providerIndex][] = $index;
                }
            }
        }

        // Kahn's algorithm
        $queue = [];
        foreach ($inDegree as $index => $degree) {
            if ($degree === 0) {
                $queue[] = $index;
            }
        }

        $sorted = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            $sorted[] = $collectorList[$current];

            foreach ($dependents[$current] as $dependentIndex) {
                $inDegree[$dependentIndex]--;
                if ($inDegree[$dependentIndex] === 0) {
                    $queue[] = $dependentIndex;
                }
            }
        }

        // If not all collectors are sorted, there's a cycle
        if (\count($sorted) !== \count($collectorList)) {
            $cycle = $this->findCycle($collectorList, $inDegree);
            throw new CyclicDependencyException($cycle);
        }

        return $sorted;
    }

    /**
     * Converts iterable to list.
     *
     * @param iterable<GlobalContextCollectorInterface> $collectors
     *
     * @return list<GlobalContextCollectorInterface>
     */
    private function toList(iterable $collectors): array
    {
        if (\is_array($collectors)) {
            return array_values($collectors);
        }

        return iterator_to_array($collectors, false);
    }

    /**
     * Builds a map of metric name → collector name that provides it.
     *
     * @param list<GlobalContextCollectorInterface> $collectors
     *
     * @return array<string, string>
     */
    private function buildProvidersMap(array $collectors): array
    {
        $providers = [];

        foreach ($collectors as $collector) {
            $collectorName = $collector->getName();
            foreach ($collector->provides() as $metric) {
                $providers[$metric] = $collectorName;
            }
        }

        return $providers;
    }

    /**
     * Finds which collectors provide the metrics required by this collector.
     *
     * @param array<string, string> $providers Metric → collector name mapping
     *
     * @return list<string> Unique collector names
     */
    private function findRequiredProviders(
        GlobalContextCollectorInterface $collector,
        array $providers,
    ): array {
        $required = [];

        foreach ($collector->requires() as $metric) {
            // Handle dotted metric names (e.g., 'classCount.sum' → 'classCount')
            $baseMetric = $this->getBaseMetric($metric);

            if (isset($providers[$metric])) {
                $required[$providers[$metric]] = true;
            } elseif (isset($providers[$baseMetric])) {
                $required[$providers[$baseMetric]] = true;
            }
        }

        return array_keys($required);
    }

    /**
     * Extracts base metric name from a potentially dotted name.
     *
     * 'classCount.sum' → 'classCount'
     * 'instability' → 'instability'
     */
    private function getBaseMetric(string $metric): string
    {
        $dotPos = strpos($metric, '.');
        if ($dotPos !== false) {
            return substr($metric, 0, $dotPos);
        }

        return $metric;
    }

    /**
     * Finds collectors forming a cycle (those with remaining in-degree > 0).
     *
     * @param list<GlobalContextCollectorInterface> $collectors
     * @param array<int, int> $inDegree
     *
     * @return list<string>
     */
    private function findCycle(array $collectors, array $inDegree): array
    {
        $cycleCollectors = [];

        foreach ($inDegree as $index => $degree) {
            if ($degree > 0) {
                $cycleCollectors[] = $collectors[$index]->getName();
            }
        }

        return $cycleCollectors;
    }
}
