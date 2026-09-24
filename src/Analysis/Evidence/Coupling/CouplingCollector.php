<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\GlobalContextCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Computes coupling metrics from the dependency graph.
 *
 * Metrics computed:
 * - ca: Afferent Coupling (incoming dependencies)
 * - ce: Efferent Coupling (outgoing dependencies)
 * - cbo: Coupling Between Objects (union of coupled classes, per C&K)
 * - instability: I = Ce / (Ca + Ce), range [0, 1]
 * - cbo_app: Application-only CBO (excludes framework dependencies)
 * - ce_framework: Count of framework efferent dependencies
 *
 * Computes metrics for both classes and namespaces.
 */
final class CouplingCollector implements GlobalContextCollectorInterface
{
    public function __construct(
        private readonly CouplingAnalysis $couplingAnalysis,
    ) {}

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
        return [
            MetricName::COUPLING_CA,
            MetricName::COUPLING_CE,
            MetricName::COUPLING_CBO,
            MetricName::COUPLING_INSTABILITY,
            MetricName::COUPLING_CE_PACKAGES,
            MetricName::COUPLING_CBO_APP,
            MetricName::COUPLING_CE_FRAMEWORK,
            MetricName::COUPLING_CA_OWN,
            MetricName::COUPLING_CE_OWN,
            MetricName::COUPLING_CBO_OWN,
            MetricName::COUPLING_INSTABILITY_OWN,
        ];
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
            new MetricDefinition(
                name: MetricName::COUPLING_CBO_APP,
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
                name: MetricName::COUPLING_CE_FRAMEWORK,
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
                name: MetricName::COUPLING_CA_OWN,
                collectedAt: SymbolLevel::Namespace_,
                aggregations: [],
            ),
            new MetricDefinition(
                name: MetricName::COUPLING_CE_OWN,
                collectedAt: SymbolLevel::Namespace_,
                aggregations: [],
            ),
            new MetricDefinition(
                name: MetricName::COUPLING_CBO_OWN,
                collectedAt: SymbolLevel::Namespace_,
                aggregations: [],
            ),
            new MetricDefinition(
                name: MetricName::COUPLING_INSTABILITY_OWN,
                collectedAt: SymbolLevel::Namespace_,
                aggregations: [],
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
     * Computes Ca, Ce, CBO, Instability, CBO_APP, CE_FRAMEWORK for each class in the graph.
     *
     * CBO (Coupling Between Objects) per Chidamber & Kemerer is the count of
     * uniquely coupled classes — the union of incoming and outgoing dependencies.
     * If A→B and B→A, CBO(A) = 1, not 2.
     *
     * CBO_APP excludes dependencies on configured framework namespaces.
     * CE_FRAMEWORK counts only efferent dependencies to framework namespaces.
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
            // CBO_APP = CBO excluding framework classes
            $coupledAppClasses = [];
            // CE_FRAMEWORK = count of unique framework efferent dependencies
            $frameworkCeTargets = [];

            foreach ($graph->getClassDependencies($symbolPath) as $dep) {
                $targetCanonical = $dep->targetLogical()->toCanonical();
                $coupledClasses[$targetCanonical] = true;

                if ($this->isFrameworkSymbol($dep->targetLogical())) {
                    $frameworkCeTargets[$targetCanonical] = true;
                } else {
                    $coupledAppClasses[$targetCanonical] = true;
                }
            }

            foreach ($graph->getClassDependents($symbolPath) as $dep) {
                $sourceCanonical = $dep->sourceLogical()->toCanonical();
                $coupledClasses[$sourceCanonical] = true;

                // Afferent from framework is unlikely (framework classes aren't scanned),
                // but handle correctly anyway
                if (!$this->isFrameworkSymbol($dep->sourceLogical())) {
                    $coupledAppClasses[$sourceCanonical] = true;
                }
            }

            $cbo = \count($coupledClasses);
            $cboApp = \count($coupledAppClasses);
            $ceFramework = \count($frameworkCeTargets);
            $instability = $this->computeInstability($ca, $ce);

            // Count distinct top-level namespaces (vendor packages) among efferent deps,
            // excluding the source class's own top-level namespace.
            $sourceTopNs = $this->getTopLevelNamespace($symbolPath->namespace);
            $externalPackages = [];

            foreach ($graph->getClassDependencies($symbolPath) as $dep) {
                $targetTopNs = $this->getTopLevelNamespace($dep->targetLogical()->namespace);

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
                ->with(MetricName::COUPLING_CE_PACKAGES, $cePackages)
                ->with(MetricName::COUPLING_CBO_APP, $cboApp)
                ->with(MetricName::COUPLING_CE_FRAMEWORK, $ceFramework);

            $repository->add($symbolPath, $metrics, null, 0);
        }
    }

    /**
     * Computes Ca, Ce, CBO, Instability for each namespace in the graph, in
     * both of the scopes the graph distinguishes: the subtree rollup a parent
     * namespace publishes, and the own scope of the declarations it holds
     * itself. For a namespace without sub-namespaces the two coincide.
     *
     * CBO at namespace level counts uniquely coupled external namespaces (union of
     * incoming and outgoing namespace dependencies). If namespace A depends on B
     * and B depends on A, CBO(A) = 1 (not 2), mirroring the class-level C&K definition.
     * It is taken over the same region as the published Ca and Ce: the subtree
     * (see {@see CoupledNamespaces}).
     *
     * `coupling.cbo-own` is the same count over the namespace's own
     * declarations, published only on a namespace declaring a type the run
     * analysed: a namespace with no declarations of its own is no package, and
     * a 0 would read as a measured one.
     */
    private function computeNamespaceMetrics(
        DependencyGraphInterface $graph,
        MetricRepositoryInterface $repository,
    ): void {
        $coupledNamespaces = CoupledNamespaces::of($graph);
        $declaring = $this->namespacesDeclaringAnAnalysedType($graph, $repository);

        foreach ($graph->getAllNamespaces() as $symbolPath) {
            // Skip namespaces not in the repository (e.g. vendor namespaces)
            if (!$repository->has($symbolPath)) {
                continue;
            }

            $ca = $graph->getNamespaceCa($symbolPath);
            $ce = $graph->getNamespaceCe($symbolPath);
            $cbo = $coupledNamespaces->countFor($symbolPath->namespace ?? '');
            $instability = $this->computeInstability($ca, $ce);
            $ownCa = $graph->getNamespaceOwnCa($symbolPath);
            $ownCe = $graph->getNamespaceOwnCe($symbolPath);

            $metrics = (new MetricBag())
                ->with(MetricName::COUPLING_CA, $ca)
                ->with(MetricName::COUPLING_CE, $ce)
                ->with(MetricName::COUPLING_CBO, $cbo)
                ->with(MetricName::COUPLING_INSTABILITY, $instability)
                ->with(MetricName::COUPLING_CA_OWN, $ownCa)
                ->with(MetricName::COUPLING_CE_OWN, $ownCe)
                ->with(MetricName::COUPLING_INSTABILITY_OWN, $this->computeInstability($ownCa, $ownCe));

            $namespace = $symbolPath->namespace ?? '';
            if (isset($declaring[$namespace])) {
                $metrics = $metrics->with(MetricName::COUPLING_CBO_OWN, $coupledNamespaces->ownCountFor($namespace));
            }

            $repository->add($symbolPath, $metrics, null, null);
        }
    }

    /**
     * @return array<string, true>
     */
    private function namespacesDeclaringAnAnalysedType(
        DependencyGraphInterface $graph,
        MetricRepositoryInterface $repository,
    ): array {
        $declaring = [];

        foreach ($graph->getAllClasses() as $class) {
            if ($class->namespace !== null && $repository->has($class)) {
                $declaring[$class->namespace] = true;
            }
        }

        return $declaring;
    }

    /**
     * The framework classification this walk performs is enumerated by
     * {@see FrameworkClassificationSites}, beside it and not inside the rule
     * that consumes it. The two positions below — the target of a measured
     * class's outgoing edge, the source of its incoming one — are the whole of
     * it, and `FrameworkClassificationSiteCountTest` pins that there are no
     * others, because nothing in the language keeps a mirror a mirror.
     */
    /**
     * Checks if a SymbolPath represents a framework class.
     */
    private function isFrameworkSymbol(
        SymbolPath $symbolPath,
    ): bool {
        if ($this->couplingAnalysis->isEmpty()) {
            return false;
        }

        // Build the FQCN from namespace and type for matching
        $namespace = $symbolPath->namespace ?? '';
        $type = $symbolPath->type ?? '';

        if ($namespace === '' && $type === '') {
            return false;
        }

        $fqcn = $namespace !== '' ? $namespace . '\\' . $type : $type;

        return $this->couplingAnalysis->isFramework($fqcn);
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
