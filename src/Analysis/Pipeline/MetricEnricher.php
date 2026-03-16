<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Pipeline;

use AiMessDetector\Analysis\Aggregator\AggregationHelper;
use AiMessDetector\Analysis\Aggregator\GlobalCollectorRunner;
use AiMessDetector\Analysis\Aggregator\MetricAggregator;
use AiMessDetector\Analysis\Collection\Dependency\CircularDependencyDetector;
use AiMessDetector\Analysis\Collection\Metric\CompositeCollector;
use AiMessDetector\Analysis\Duplication;
use AiMessDetector\Configuration\ConfigurationProviderInterface;
use AiMessDetector\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use AiMessDetector\Core\Dependency\DependencyGraphInterface;
use AiMessDetector\Core\Metric\MetricDefinition;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Profiler\ProfilerHolder;
use AiMessDetector\Metrics\ComputedMetric\ComputedMetricEvaluator;
use AiMessDetector\Rules\Architecture\CircularDependencyRule;
use AiMessDetector\Rules\Duplication\CodeDuplicationRule;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
final class MetricEnricher
{
    /** @var list<MetricDefinition> */
    private readonly array $allDefinitions;

    /** @var list<MetricDefinition> */
    private readonly array $globalDefinitions;

    public function __construct(
        private readonly CompositeCollector $compositeCollector,
        private readonly GlobalCollectorRunner $globalCollectorRunner,
        private readonly ConfigurationProviderInterface $configurationProvider,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?ProfilerHolder $profilerHolder = null,
        private readonly ?Duplication\DuplicationDetector $duplicationDetector = null,
        private readonly ?ComputedMetricEvaluator $computedMetricEvaluator = null,
    ) {
        // Collect ALL definitions from regular collectors, derived collectors, AND global collectors
        $regularDefinitions = AggregationHelper::collectDefinitions($this->compositeCollector->getCollectors());
        $derivedDefinitions = [];
        foreach ($this->compositeCollector->getDerivedCollectors() as $derived) {
            foreach ($derived->getMetricDefinitions() as $def) {
                $derivedDefinitions[] = $def;
            }
        }
        $globalDefs = [];
        foreach ($this->globalCollectorRunner->getCollectors() as $global) {
            foreach ($global->getMetricDefinitions() as $def) {
                $globalDefs[] = $def;
            }
        }
        $this->globalDefinitions = $globalDefs;
        $this->allDefinitions = array_merge($regularDefinitions, $derivedDefinitions, $globalDefs);
    }

    /**
     * Enriches the metric repository with aggregated, global, and computed metrics.
     *
     * @param list<SplFileInfo> $files Files for duplication detection
     * @param int $filesAnalyzed Number of files successfully analyzed
     */
    public function enrich(
        MetricRepositoryInterface $repository,
        DependencyGraphInterface $graph,
        array $files,
        int $filesAnalyzed,
    ): EnrichmentResult {
        $profiler = $this->profilerHolder?->get();
        $config = $this->configurationProvider->getConfiguration();

        // Phase 3: Aggregation (regular + derived collector definitions)
        $phaseStartTime = microtime(true);
        $this->logger->debug('Starting aggregation phase');

        $profiler?->start('aggregation', 'pipeline');
        $aggregator = new MetricAggregator($this->allDefinitions);
        $aggregator->aggregate($repository);
        $profiler?->stop('aggregation');

        $aggregationTime = microtime(true) - $phaseStartTime;
        $this->logger->info('Aggregation completed', [
            'duration' => \sprintf('%.2fs', $aggregationTime),
        ]);

        // Phase 3.5: Global collectors (coupling metrics based on graph)
        if ($this->globalCollectorRunner->hasCollectors()) {
            $this->logger->debug('Running global collectors', [
                'count' => $this->globalCollectorRunner->count(),
            ]);
        }
        $profiler?->start('global', 'pipeline');
        $this->globalCollectorRunner->run($graph, $repository);
        $profiler?->stop('global');

        // Phase 3.6: Re-aggregate global collector metrics to namespace/project level
        if ($this->globalDefinitions !== []) {
            $profiler?->start('aggregation.global', 'pipeline');
            $globalAggregator = new MetricAggregator($this->globalDefinitions);
            $globalAggregator->aggregate($repository);
            $profiler?->stop('aggregation.global');
        }

        // Phase 3.65: Computed metrics (health scores) — skip when no files were analyzed
        $definitions = ComputedMetricDefinitionHolder::getDefinitions();
        if ($definitions !== [] && $this->computedMetricEvaluator !== null && $filesAnalyzed > 0) {
            $profiler?->start('computed', 'pipeline');
            $this->computedMetricEvaluator->compute($repository, $definitions);
            $profiler?->stop('computed');
        }

        // Phase 3.7: Detect circular dependencies
        $cycles = [];
        if ($config->isRuleEnabled(CircularDependencyRule::NAME)) {
            $profiler?->start('cycles', 'pipeline');
            $cycles = (new CircularDependencyDetector())->detect($graph);
            $profiler?->stop('cycles');
        }

        // Phase 3.8: Detect code duplication
        $duplicateBlocks = [];
        if ($this->duplicationDetector !== null && $config->isRuleEnabled(CodeDuplicationRule::NAME)) {
            $profiler?->start('duplication', 'pipeline');
            $duplicateBlocks = $this->duplicationDetector->detect($files);
            $profiler?->stop('duplication');

            $this->logger->info('Duplication detection completed', [
                'blocks' => \count($duplicateBlocks),
            ]);
        }

        return new EnrichmentResult($cycles, $duplicateBlocks);
    }
}
