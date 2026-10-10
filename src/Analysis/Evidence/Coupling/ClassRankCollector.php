<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassKeyScope;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\GlobalContextCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Symbol\SymbolLevel;

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
 *
 * Namespace aggregates sample each physical class declaration: the logical-name
 * ClassRank value repeats in every declaration's view. Their sum/count/average
 * are declaration-weighted; the underlying graph algorithm still uses logical names.
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
        return [MetricName::COUPLING_CLASS_RANK, MetricName::COUPLING_CLASS_RANK_SHARE];
    }

    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition(
                name: MetricName::COUPLING_CLASS_RANK,
                collectedAt: SymbolLevel::Class_,
                classKeyScope: ClassKeyScope::LogicalName,
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
            new MetricDefinition(
                name: MetricName::COUPLING_CLASS_RANK_SHARE,
                collectedAt: SymbolLevel::Class_,
                classKeyScope: ClassKeyScope::LogicalName,
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
            if ($repository->hasSubject(\Qualimetrix\Core\Symbol\MetricSubject::logicalClass(new \Qualimetrix\Core\Symbol\LogicalClassPath($symbolPath)))) {
                $projectClasses[$symbolPath->toCanonical()] = $symbolPath;
            }
        }

        $projectClasses = array_values($projectClasses);
        $n = \count($projectClasses);

        // Empty graph: skip, no metrics written
        if ($n === 0) {
            return;
        }

        // Single class: rank = 1.0
        if ($n === 1) {
            $repository->addSubjectScalar(\Qualimetrix\Core\Symbol\MetricSubject::logicalClass(new \Qualimetrix\Core\Symbol\LogicalClassPath($projectClasses[0])), MetricName::COUPLING_CLASS_RANK, 1.0);
            $repository->addSubjectScalar(\Qualimetrix\Core\Symbol\MetricSubject::logicalClass(new \Qualimetrix\Core\Symbol\LogicalClassPath($projectClasses[0])), MetricName::COUPLING_CLASS_RANK_SHARE, 1.0);

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
                $targetKey = $dep->targetLogical()->toCanonical();

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
            $repository->addSubjectScalar(\Qualimetrix\Core\Symbol\MetricSubject::logicalClass(new \Qualimetrix\Core\Symbol\LogicalClassPath($symbolPath)), MetricName::COUPLING_CLASS_RANK, $ranks[$i]);
            $repository->addSubjectScalar(\Qualimetrix\Core\Symbol\MetricSubject::logicalClass(new \Qualimetrix\Core\Symbol\LogicalClassPath($symbolPath)), MetricName::COUPLING_CLASS_RANK_SHARE, $ranks[$i] * $n);
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
