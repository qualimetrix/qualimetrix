<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\Inheritance;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\GlobalContextCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

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
 * Anonymous classes never contribute to NOC: they have no declaration
 * identity a named class could `extends`, and their own `extends` edge is
 * flagged as a nested anonymous-class declaration fact of the enclosing
 * class (see {@see DependencyType::Extends} and
 * {@see \Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency::$describesNestedAnonymousClass}),
 * so it is excluded below rather than counted against the enclosing class.
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
        return [MetricName::DESIGN_NOC];
    }

    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition(
                name: MetricName::DESIGN_NOC,
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
        foreach ($childrenMap as $children) {
            $parentPath = $children['symbolPath'];

            // Skip classes not in the repository (e.g. vendor classes)
            if (!$repository->has($parentPath)) {
                continue;
            }

            $noc = \count($children['children']);

            $repository->addScalar($parentPath, MetricName::DESIGN_NOC, $noc);
        }

        // Step 3: Ensure all classes have NOC (even if 0)
        // Iterate all classes from repository and set NOC=0 if not set
        foreach ($repository->all(SymbolLevel::Class_) as $classSymbol) {
            if (!$repository->has($classSymbol->symbolPath)) {
                continue;
            }

            if (!$repository->get($classSymbol->symbolPath)->has(MetricName::DESIGN_NOC)) {
                $repository->addScalar($classSymbol->symbolPath, MetricName::DESIGN_NOC, 0);
            }
        }
    }

    /**
     * Builds a map of parent canonical key → {symbolPath, children} from dependency graph.
     *
     * Only counts DependencyType::Extends (not implements or trait use).
     *
     * Children are collected as a set of names rather than counted as edges.
     * A name declared in two files — the `class_exists()`-guarded polyfill
     * shape — produces two edges, and a hierarchy in which only one of them
     * can exist has one subclass by that name, not two.
     *
     * @return array<string, array{symbolPath: SymbolPath, children: array<string, true>}>
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

            // An anonymous class's own `extends` is recorded with the
            // enclosing class as source (it has no declaration identity of
            // its own) — the enclosing class does not gain a child from it.
            if ($dependency->describesNestedAnonymousClass) {
                continue;
            }

            $parentKey = $dependency->targetLogical()->toCanonical();

            // Add to children map
            if (!isset($childrenMap[$parentKey])) {
                $childrenMap[$parentKey] = [
                    'symbolPath' => $dependency->targetLogical(),
                    'children' => [],
                ];
            }
            $childrenMap[$parentKey]['children'][$dependency->sourceLogical()->toCanonical()] = true;
        }

        return $childrenMap;
    }
}
