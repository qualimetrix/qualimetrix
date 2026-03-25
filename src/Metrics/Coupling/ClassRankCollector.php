<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Coupling;

use Qualimetrix\Core\Dependency\DependencyGraphInterface;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\GlobalContextCollectorInterface;
use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Metric\SymbolLevel;

/**
 * Computes ClassRank using the PageRank algorithm on the dependency graph.
 *
 * Direction: A depends on B => A "votes" for B (link from A to B).
 * This means classes with many dependents (high afferent coupling) get higher ranks.
 *
 * Dangling nodes (classes with no outgoing dependencies) distribute their
 * weight evenly across all nodes.
 *
 * Parameters:
 * - Damping factor: 0.85
 * - Convergence epsilon: 1e-6
 * - Maximum iterations: 100
 */
final class ClassRankCollector implements GlobalContextCollectorInterface
{
    private const float DAMPING_FACTOR = 0.85;
    private const float EPSILON = 1e-6;
    private const int MAX_ITERATIONS = 100;

    public function getName(): string
    {
        return 'classRank';
    }

    public function requires(): array
    {
        return [MetricName::COUPLING_CA, MetricName::COUPLING_CE];
    }

    public function provides(): array
    {
        return [MetricName::COUPLING_CLASS_RANK];
    }

    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition(
                name: MetricName::COUPLING_CLASS_RANK,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [
                        AggregationStrategy::Max,
                        AggregationStrategy::Average,
                        AggregationStrategy::Percentile95,
                    ],
                    SymbolLevel::Project->value => [
                        AggregationStrategy::Max,
                        AggregationStrategy::Average,
                        AggregationStrategy::Percentile95,
                    ],
                ],
            ),
        ];
    }

    public function calculate(
        DependencyGraphInterface $graph,
        MetricRepositoryInterface $repository,
    ): void {
        // Build adjacency list from dependency graph, only for project classes
        $allClasses = $graph->getAllClasses();

        // Filter to only project classes (those in the repository)
        $projectClasses = [];
        foreach ($allClasses as $symbolPath) {
            if ($repository->has($symbolPath)) {
                $projectClasses[] = $symbolPath;
            }
        }

        $n = \count($projectClasses);

        // Empty graph: skip, no metrics written
        if ($n === 0) {
            return;
        }

        // Single class: rank = 1.0
        if ($n === 1) {
            $repository->addScalar($projectClasses[0], MetricName::COUPLING_CLASS_RANK, 1.0);

            return;
        }

        // Build index: canonical string -> integer index
        /** @var array<string, int> $classIndex */
        $classIndex = [];
        foreach ($projectClasses as $i => $symbolPath) {
            $classIndex[$symbolPath->toCanonical()] = $i;
        }

        // Build outgoing links for each node (A depends on B => link A->B)
        // Only include links where both source and target are project classes
        /** @var array<int, list<int>> $outLinks */
        $outLinks = array_fill(0, $n, []);

        foreach ($projectClasses as $i => $symbolPath) {
            $seen = [];
            foreach ($graph->getClassDependencies($symbolPath) as $dep) {
                $targetKey = $dep->target->toCanonical();

                // Skip self-dependencies
                if ($targetKey === $symbolPath->toCanonical()) {
                    continue;
                }

                // Skip targets not in project (vendor/external)
                if (!isset($classIndex[$targetKey])) {
                    continue;
                }

                // Deduplicate
                if (isset($seen[$targetKey])) {
                    continue;
                }
                $seen[$targetKey] = true;

                $outLinks[$i][] = $classIndex[$targetKey];
            }
        }

        // Compute PageRank
        $ranks = $this->computePageRank($n, $outLinks);

        // Write metrics to repository
        foreach ($projectClasses as $i => $symbolPath) {
            $repository->addScalar($symbolPath, MetricName::COUPLING_CLASS_RANK, $ranks[$i]);
        }
    }

    /**
     * Computes PageRank scores for the given graph.
     *
     * @param int $n Number of nodes
     * @param array<int, list<int>> $outLinks Adjacency list (node -> list of targets)
     *
     * @return array<int, float> PageRank scores indexed by node
     */
    private function computePageRank(int $n, array $outLinks): array
    {
        $d = self::DAMPING_FACTOR;
        $baseRank = (1.0 - $d) / $n;

        // Initialize all ranks to 1/N
        $ranks = array_fill(0, $n, 1.0 / $n);

        // Pre-compute out-degree for each node
        /** @var list<int> $outDegree */
        $outDegree = [];
        for ($i = 0; $i < $n; $i++) {
            $outDegree[$i] = \count($outLinks[$i]);
        }

        for ($iter = 0; $iter < self::MAX_ITERATIONS; $iter++) {
            // Compute dangling node contribution (nodes with no outgoing links)
            $danglingSum = 0.0;
            for ($i = 0; $i < $n; $i++) {
                if ($outDegree[$i] === 0) {
                    $danglingSum += $ranks[$i];
                }
            }
            $danglingContribution = $d * $danglingSum / $n;

            // Compute new ranks
            /** @var list<float> $newRanks */
            $newRanks = array_fill(0, $n, $baseRank + $danglingContribution);

            // Add contributions from incoming links
            for ($i = 0; $i < $n; $i++) {
                if ($outDegree[$i] === 0) {
                    continue;
                }

                $contribution = $d * $ranks[$i] / $outDegree[$i];
                foreach ($outLinks[$i] as $target) {
                    $newRanks[$target] += $contribution;
                }
            }

            // Check convergence
            $diff = 0.0;
            for ($i = 0; $i < $n; $i++) {
                $diff += abs($newRanks[$i] - $ranks[$i]);
            }

            $ranks = $newRanks;

            if ($diff < self::EPSILON) {
                break;
            }
        }

        return $ranks;
    }
}
