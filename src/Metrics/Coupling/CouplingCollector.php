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

/**
 * Computes coupling metrics from the dependency graph.
 *
 * Metrics computed:
 * - ca: Afferent Coupling (incoming dependencies)
 * - ce: Efferent Coupling (outgoing dependencies)
 * - cbo: Coupling Between Objects (union of coupled classes, per C&K)
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
     *
     * CBO (Coupling Between Objects) per Chidamber & Kemerer is the count of
     * uniquely coupled classes — the union of incoming and outgoing dependencies.
     * If A→B and B→A, CBO(A) = 1, not 2.
     */
    private function computeClassMetrics(
        DependencyGraphInterface $graph,
        MetricRepositoryInterface $repository,
    ): void {
        foreach ($graph->getAllClasses() as $symbolPath) {
            // Skip classes not in the repository (e.g. vendor/external classes)
            if (!$repository->has($symbolPath)) {
                continue;
            }

            $ca = $graph->getClassCa($symbolPath);
            $ce = $graph->getClassCe($symbolPath);

            // CBO = |union of Ce targets and Ca sources| (C&K definition)
            $coupledClasses = [];

            foreach ($graph->getClassDependencies($symbolPath) as $dep) {
                $coupledClasses[$dep->target->toCanonical()] = true;
            }

            foreach ($graph->getClassDependents($symbolPath) as $dep) {
                $coupledClasses[$dep->source->toCanonical()] = true;
            }

            $cbo = \count($coupledClasses);
            $instability = $this->computeInstability($ca, $ce);

            $metrics = (new MetricBag())
                ->with('ca', $ca)
                ->with('ce', $ce)
                ->with('cbo', $cbo)
                ->with('instability', $instability);

            $repository->add($symbolPath, $metrics, '', 0);
        }
    }

    /**
     * Computes Ca, Ce, CBO, Instability for each namespace in the graph.
     *
     * CBO at namespace level counts uniquely coupled external namespaces (union of
     * incoming and outgoing namespace dependencies). If namespace A depends on B
     * and B depends on A, CBO(A) = 1 (not 2), mirroring the class-level C&K definition.
     */
    private function computeNamespaceMetrics(
        DependencyGraphInterface $graph,
        MetricRepositoryInterface $repository,
    ): void {
        // Pre-compute coupled namespace sets from the full dependency list
        $coupledNamespaces = $this->buildCoupledNamespaceSets($graph);

        foreach ($graph->getAllNamespaces() as $symbolPath) {
            // Skip namespaces not in the repository (e.g. vendor namespaces)
            if (!$repository->has($symbolPath)) {
                continue;
            }

            $ca = $graph->getNamespaceCa($symbolPath);
            $ce = $graph->getNamespaceCe($symbolPath);
            $nsKey = $symbolPath->namespace ?? '';
            $cbo = \count($coupledNamespaces[$nsKey] ?? []);
            $instability = $this->computeInstability($ca, $ce);

            $metrics = (new MetricBag())
                ->with('ca', $ca)
                ->with('ce', $ce)
                ->with('cbo', $cbo)
                ->with('instability', $instability);

            $repository->add($symbolPath, $metrics, '', null);
        }
    }

    /**
     * Builds a map of namespace -> set of uniquely coupled external namespaces.
     *
     * For each cross-namespace dependency, both the source and target namespace
     * get the other namespace added to their coupled set.
     *
     * @return array<string, array<string, true>> Namespace name -> set of coupled namespace names
     */
    private function buildCoupledNamespaceSets(DependencyGraphInterface $graph): array
    {
        $coupled = [];

        foreach ($graph->getAllDependencies() as $dep) {
            $sourceNs = $dep->source->namespace ?? '';
            $targetNs = $dep->target->namespace ?? '';

            // Only count cross-namespace dependencies
            if ($sourceNs === $targetNs) {
                continue;
            }

            $coupled[$sourceNs][$targetNs] = true;
            $coupled[$targetNs][$sourceNs] = true;
        }

        return $coupled;
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
}
