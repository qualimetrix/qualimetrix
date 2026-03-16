<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Structure;

use AiMessDetector\Core\Dependency\DependencyGraphInterface;
use AiMessDetector\Core\Dependency\DependencyType;
use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\GlobalContextCollectorInterface;
use AiMessDetector\Core\Metric\MetricDefinition;
use AiMessDetector\Core\Metric\MetricName;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;

/**
 * Computes Number of Children (NOC) metric from dependency graph.
 *
 * NOC measures the number of direct subclasses (extends) for each class.
 * Higher NOC indicates wider reuse and potentially greater impact of changes.
 *
 * This collector uses the dependency graph to find inheritance relationships.
 *
 * Only direct children are counted:
 * - A extends B, C extends B → NOC(B) = 2
 * - D extends C → does NOT increase NOC(B)
 *
 * Design decision: only `extends` is counted, NOT `implements` or trait `use`.
 * This follows the canonical Chidamber & Kemerer (1994) definition where NOC
 * measures class inheritance hierarchy depth, not interface contracts.
 * Interface implementations represent a different type of relationship
 * (contractual, not structural) and should be tracked separately if needed.
 *
 * Anonymous classes are ignored (not in dependency graph).
 */
final class NocCollector implements GlobalContextCollectorInterface
{
    private const NAME = 'noc';

    public function getName(): string
    {
        return self::NAME;
    }

    public function requires(): array
    {
        return [];
    }

    public function provides(): array
    {
        return [MetricName::STRUCTURE_NOC];
    }

    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition(
                name: MetricName::STRUCTURE_NOC,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [
                        AggregationStrategy::Sum,
                        AggregationStrategy::Average,
                        AggregationStrategy::Max,
                    ],
                    SymbolLevel::Project->value => [
                        AggregationStrategy::Sum,
                        AggregationStrategy::Average,
                        AggregationStrategy::Max,
                    ],
                ],
            ),
        ];
    }

    public function calculate(
        DependencyGraphInterface $graph,
        MetricRepositoryInterface $repository,
    ): void {
        // Step 1: Build parent → children map from dependency graph
        $childrenMap = $this->buildChildrenMapFromGraph($graph);

        // Step 2: Store NOC for each class that has children (only project classes)
        foreach ($childrenMap as $parentKey => $children) {
            $parentPath = $children['symbolPath'];

            // Skip classes not in the repository (e.g. vendor classes)
            if (!$repository->has($parentPath)) {
                continue;
            }

            $noc = $children['count'];

            $repository->addScalar($parentPath, MetricName::STRUCTURE_NOC, $noc);
        }

        // Step 3: Ensure all classes have NOC (even if 0)
        // Iterate all classes from repository and set NOC=0 if not set
        foreach ($repository->all(SymbolType::Class_) as $classSymbol) {
            if (!$repository->has($classSymbol->symbolPath)) {
                continue;
            }

            if (!$repository->get($classSymbol->symbolPath)->has(MetricName::STRUCTURE_NOC)) {
                $repository->addScalar($classSymbol->symbolPath, MetricName::STRUCTURE_NOC, 0);
            }
        }
    }

    /**
     * Builds a map of parent canonical key → {symbolPath, count} from dependency graph.
     *
     * Only counts DependencyType::Extends (not implements or trait use).
     *
     * @return array<string, array{symbolPath: SymbolPath, count: int}>
     */
    private function buildChildrenMapFromGraph(DependencyGraphInterface $graph): array
    {
        $childrenMap = [];

        // Iterate all dependencies and filter for extends relationships
        foreach ($graph->getAllDependencies() as $dependency) {
            // Only count extends (inheritance), not implements or trait use
            if ($dependency->type !== DependencyType::Extends) {
                continue;
            }

            $parentKey = $dependency->target->toCanonical();

            // Add to children map
            if (!isset($childrenMap[$parentKey])) {
                $childrenMap[$parentKey] = [
                    'symbolPath' => $dependency->target,
                    'count' => 0,
                ];
            }
            $childrenMap[$parentKey]['count']++;
        }

        return $childrenMap;
    }
}
