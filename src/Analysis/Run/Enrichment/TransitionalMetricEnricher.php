<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Enrichment;

use Qualimetrix\Analysis\Collection\Dependency\CircularDependencyDetector;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfigurationProviderInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MeasurementAggregationInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Run\FileSetInspection\FileSetInspectionComposite;
use Qualimetrix\Architecture\Rules\CircularDependencyRule;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Core\Rule\InMemoryRuleChannelRegistry;
use Qualimetrix\Core\Rule\RuleSelector;
use Qualimetrix\Metrics\ComputedMetric\ComputedMetricEvaluator;
use SplFileInfo;

/**
 * Enriches collected metrics with aggregated, global, and computed values.
 *
 * Handles phases 3-3.8 of the analysis pipeline:
 * - Aggregation (method → class → namespace → project)
 * - Global collectors (CBO, DIT, NOC from dependency graph)
 * - Re-aggregation of global metrics
 * - Computed metrics (health scores)
 * - Circular dependency detection
 * - Code duplication detection
 */
final class TransitionalMetricEnricher
{
    public function __construct(
        private readonly MeasurementAggregationInterface $measurementAggregation,
        private readonly TransitionalRuntimeConfigurationProviderInterface $configurationProvider,
        private readonly ?ProfilerHolder $profilerHolder = null,
        private readonly ?FileSetInspectionComposite $fileSetInspection = null,
        private readonly ?ComputedMetricEvaluator $computedMetricEvaluator = null,
        private readonly RuleSelector $ruleSelector = new RuleSelector(new InMemoryRuleChannelRegistry()),
    ) {}

    /**
     * Enriches the metric repository with aggregated, global, and computed metrics.
     *
     * The CCN here counts enrichment phases, not nesting: the method is a linear
     * sequence of optional steps, each guarded by its own independent feature
     * check. Duplication's reset and selection form one lifecycle operation;
     * its helper keeps that invariant local while this method retains the
     * visible phase order.
     *
     * @param list<SplFileInfo> $files Files for duplication detection
     * @param int $filesAnalyzed Number of files successfully analyzed
     *
     * @qmx-threshold complexity.cyclomatic warning=25 error=35 — Linear enrichment pipeline keeps independent feature checks visible.
     * @qmx-threshold complexity.npath warning=67201 error=67201 — Finite ordered enrichment matrix keeps phase order and independent gates visible.
     */
    public function enrich(
        MetricRepositoryInterface $repository,
        DependencyGraphInterface $graph,
        array $files,
        int $filesAnalyzed,
    ): TransitionalEnrichmentResult {
        $profiler = $this->profilerHolder?->get(); // @phpstan-ignore staticMethod.dynamicCall
        $config = $this->configurationProvider->getConfiguration();

        $namespaceTree = $this->measurementAggregation->aggregate($repository, $graph);

        // Phase 3.65: Computed metrics (health scores) — skip when no files were analyzed
        $definitions = ComputedMetricDefinitionHolder::getDefinitions();
        if ($definitions !== [] && $this->computedMetricEvaluator !== null && $filesAnalyzed > 0) {
            $profiler?->start('computed', 'pipeline');
            $this->computedMetricEvaluator->compute($repository, $definitions);
            $profiler?->stop('computed');
        }

        // Phase 3.7: Detect circular dependencies
        $cycles = [];
        if ($this->ruleSelector->isProducerEnabled(
            CircularDependencyRule::NAME,
            $config->onlyRules,
            $config->disabledRules,
        )) {
            $profiler?->start('cycles', 'pipeline');
            $cycles = (new CircularDependencyDetector())->detect($graph);
            $profiler?->stop('cycles');
        }

        // Phase 3.8: Detect code duplication
        $this->fileSetInspection?->inspect($files, $config->onlyRules, $config->disabledRules);

        return new TransitionalEnrichmentResult($namespaceTree, $cycles);
    }

}
