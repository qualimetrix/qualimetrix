<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Coupling;

use AiMessDetector\Core\Dependency\DependencyGraphInterface;
use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\GlobalContextCollectorInterface;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricDefinition;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Core\Violation\SymbolPath;

/**
 * Computes coupling metrics from the dependency graph.
 *
 * Metrics computed:
 * - ca: Afferent Coupling (incoming dependencies)
 * - ce: Efferent Coupling (outgoing dependencies)
 * - cbo: Coupling Between Objects (Ca + Ce)
 * - instability: I = Ce / (Ca + Ce), range [0, 1]
 *
 * Computes metrics for both classes and namespaces.
 */
final class CouplingCollector implements GlobalContextCollectorInterface
{
    public function getName(): string
    {
        return 'coupling';
    }

    public function requires(): array
    {
        return [];
    }

    public function provides(): array
    {
        return ['ca', 'ce', 'cbo', 'instability'];
    }

    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition(
                name: 'ca',
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
                ],
            ),
            new MetricDefinition(
                name: 'ce',
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
                ],
            ),
            new MetricDefinition(
                name: 'cbo',
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
            new MetricDefinition(
                name: 'instability',
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Average],
                ],
            ),
        ];
    }

    public function calculate(
        DependencyGraphInterface $graph,
        MetricRepositoryInterface $repository,
    ): void {
        // Compute class-level metrics
        $this->computeClassMetrics($graph, $repository);

        // Compute namespace-level metrics
        $this->computeNamespaceMetrics($graph, $repository);
    }

    /**
     * Computes Ca, Ce, CBO, Instability for each class in the graph.
     */
    private function computeClassMetrics(
        DependencyGraphInterface $graph,
        MetricRepositoryInterface $repository,
    ): void {
        foreach ($graph->getAllClasses() as $className) {
            $ca = $graph->getClassCa($className);
            $ce = $graph->getClassCe($className);
            $cbo = $ca + $ce;
            $instability = $this->computeInstability($ca, $ce);

            $metrics = (new MetricBag())
                ->with('ca', $ca)
                ->with('ce', $ce)
                ->with('cbo', $cbo)
                ->with('instability', $instability);

            // Parse class name to get namespace and type
            $parts = $this->parseClassName($className);
            $symbolPath = SymbolPath::forClass($parts['namespace'] ?? '', $parts['class']);

            $repository->add($symbolPath, $metrics, '', 0);
        }
    }

    /**
     * Computes Ca, Ce, CBO, Instability for each namespace in the graph.
     */
    private function computeNamespaceMetrics(
        DependencyGraphInterface $graph,
        MetricRepositoryInterface $repository,
    ): void {
        foreach ($graph->getAllNamespaces() as $namespace) {
            $ca = $graph->getNamespaceCa($namespace);
            $ce = $graph->getNamespaceCe($namespace);
            $cbo = $ca + $ce;
            $instability = $this->computeInstability($ca, $ce);

            $metrics = (new MetricBag())
                ->with('ca', $ca)
                ->with('ce', $ce)
                ->with('cbo', $cbo)
                ->with('instability', $instability);

            $symbolPath = SymbolPath::forNamespace($namespace);

            $repository->add($symbolPath, $metrics, '', 0);
        }
    }

    /**
     * Computes instability: I = Ce / (Ca + Ce).
     *
     * Returns 0.0 if both Ca and Ce are 0 (isolated component).
     */
    private function computeInstability(int $ca, int $ce): float
    {
        $total = $ca + $ce;
        if ($total === 0) {
            return 0.0;
        }

        return $ce / $total;
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
