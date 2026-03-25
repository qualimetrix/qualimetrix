<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Coupling;

use Qualimetrix\Core\Dependency\DependencyGraphInterface;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\GlobalContextCollectorInterface;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Metric\SymbolLevel;

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
        return [MetricName::COUPLING_CA, MetricName::COUPLING_CE, MetricName::COUPLING_CBO, MetricName::COUPLING_INSTABILITY, MetricName::COUPLING_CE_PACKAGES];
    }

    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition(
                name: MetricName::COUPLING_CA,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
                ],
            ),
            new MetricDefinition(
                name: MetricName::COUPLING_CE,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
                ],
            ),
            new MetricDefinition(
                name: MetricName::COUPLING_CBO,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [
                        AggregationStrategy::Sum,
                        AggregationStrategy::Average,
                        AggregationStrategy::Max,
                        AggregationStrategy::Percentile95,
                    ],
                    SymbolLevel::Project->value => [
                        AggregationStrategy::Sum,
                        AggregationStrategy::Average,
                        AggregationStrategy::Max,
                        AggregationStrategy::Percentile95,
                    ],
                ],
            ),
            new MetricDefinition(
                name: MetricName::COUPLING_INSTABILITY,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Average],
                ],
            ),
            new MetricDefinition(
                name: MetricName::COUPLING_CE_PACKAGES,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [
                        AggregationStrategy::Average,
                        AggregationStrategy::Max,
                        AggregationStrategy::Percentile95,
                    ],
                    SymbolLevel::Project->value => [
                        AggregationStrategy::Average,
                        AggregationStrategy::Max,
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

            // Count distinct top-level namespaces (vendor packages) among efferent deps,
            // excluding the source class's own top-level namespace.
            $sourceTopNs = $this->getTopLevelNamespace($symbolPath->namespace);
            $externalPackages = [];

            foreach ($graph->getClassDependencies($symbolPath) as $dep) {
                $targetTopNs = $this->getTopLevelNamespace($dep->target->namespace);

                if ($targetTopNs !== '' && $targetTopNs !== $sourceTopNs) {
                    $externalPackages[$targetTopNs] = true;
                }
            }

            $cePackages = \count($externalPackages);

            $metrics = (new MetricBag())
                ->with(MetricName::COUPLING_CA, $ca)
                ->with(MetricName::COUPLING_CE, $ce)
                ->with(MetricName::COUPLING_CBO, $cbo)
                ->with(MetricName::COUPLING_INSTABILITY, $instability)
                ->with(MetricName::COUPLING_CE_PACKAGES, $cePackages);

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
                ->with(MetricName::COUPLING_CA, $ca)
                ->with(MetricName::COUPLING_CE, $ce)
                ->with(MetricName::COUPLING_CBO, $cbo)
                ->with(MetricName::COUPLING_INSTABILITY, $instability);

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
     * Extracts the first segment of a namespace (top-level vendor/package).
     *
     * E.g. "PhpParser\Node\Expr" → "PhpParser", "App\Service" → "App", "" → "".
     */
    private function getTopLevelNamespace(?string $namespace): string
    {
        if ($namespace === null || $namespace === '') {
            return '';
        }

        $pos = strpos($namespace, '\\');

        return $pos !== false ? substr($namespace, 0, $pos) : $namespace;
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
