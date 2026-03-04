<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Structure;

use AiMessDetector\Core\Dependency\DependencyGraphInterface;
use AiMessDetector\Core\Dependency\DependencyType;
use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\GlobalContextCollectorInterface;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricDefinition;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\SymbolPath;

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
 * Interfaces (implements) and traits (use) are NOT counted.
 * Anonymous classes are ignored (not in dependency graph).
 */
final class NocCollector implements GlobalContextCollectorInterface
{
    private const NAME = 'noc';
    private const METRIC_NOC = 'noc';

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
        return [self::METRIC_NOC];
    }

    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition(
                name: self::METRIC_NOC,
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

        // Step 2: Store NOC for each class that has children
        foreach ($childrenMap as $parentFqn => $children) {
            $noc = \count($children);

            // Parse parent FQN to namespace and class name
            $parts = $this->parseClassName($parentFqn);
            $symbolPath = SymbolPath::forClass($parts['namespace'] ?? '', $parts['class']);

            // Get existing metrics or create new bag
            $metrics = $repository->has($symbolPath) ? $repository->get($symbolPath) : new MetricBag();

            // Add NOC metric
            $metrics = $metrics->with(self::METRIC_NOC, $noc);

            // Update repository
            $repository->add($symbolPath, $metrics, '', 0);
        }

        // Step 3: Ensure all classes have NOC (even if 0)
        // Iterate all classes from repository and set NOC=0 if not set
        foreach ($repository->all(SymbolType::Class_) as $classSymbol) {
            if (!$repository->has($classSymbol->symbolPath)) {
                continue;
            }

            $metrics = $repository->get($classSymbol->symbolPath);

            if (!$metrics->has(self::METRIC_NOC)) {
                $metrics = $metrics->with(self::METRIC_NOC, 0);
                $repository->add($classSymbol->symbolPath, $metrics, $classSymbol->file, $classSymbol->line);
            }
        }
    }

    /**
     * Builds a map of parent FQN → list of children FQNs from dependency graph.
     *
     * Only counts DependencyType::Extends (not implements or trait use).
     *
     * @return array<string, list<string>>
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

            $parentFqn = $dependency->targetClass;
            $childFqn = $dependency->sourceClass;

            // Add to children map
            if (!isset($childrenMap[$parentFqn])) {
                $childrenMap[$parentFqn] = [];
            }
            $childrenMap[$parentFqn][] = $childFqn;
        }

        return $childrenMap;
    }

    /**
     * Parses a fully qualified class name into namespace and class parts.
     *
     * @return array{namespace: string|null, class: string}
     */
    private function parseClassName(string $className): array
    {
        $pos = strrpos($className, '\\');
        if ($pos === false) {
            return ['namespace' => null, 'class' => $className];
        }

        return [
            'namespace' => substr($className, 0, $pos),
            'class' => substr($className, $pos + 1),
        ];
    }
}
