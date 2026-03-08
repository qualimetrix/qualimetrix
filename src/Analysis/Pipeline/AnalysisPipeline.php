<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Pipeline;

use AiMessDetector\Analysis\Aggregation\GlobalCollectorRunner;
use AiMessDetector\Analysis\Aggregator\AggregationHelper;
use AiMessDetector\Analysis\Aggregator\MetricAggregator;
use AiMessDetector\Analysis\Collection\CollectionOrchestratorInterface;
use AiMessDetector\Analysis\Collection\Dependency\CircularDependencyDetector;
use AiMessDetector\Analysis\Collection\Dependency\DependencyGraphBuilder;
use AiMessDetector\Analysis\Collection\Metric\CompositeCollector;
use AiMessDetector\Analysis\Discovery\FileDiscoveryInterface;
use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Analysis\RuleExecution\RuleExecutorInterface;
use AiMessDetector\Configuration\ConfigurationProviderInterface;
use AiMessDetector\Core\Metric\MetricDefinition;
use AiMessDetector\Core\Profiler\ProfilerHolder;
use AiMessDetector\Core\Rule\AnalysisContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Main analysis pipeline orchestrator.
 *
 * Coordinates all phases of static analysis:
 * 1. Discovery - Find PHP files to analyze
 * 2. Collection - Parse files and collect metrics + dependencies (single AST traversal)
 * 3. Build dependency graph from collected dependencies
 * 4. Aggregation - Aggregate metrics
 * 5. Global collectors - Cross-file metrics
 * 6. Rule execution - Run analysis rules
 */
final class AnalysisPipeline implements AnalysisPipelineInterface
{
    private readonly DependencyGraphBuilder $graphBuilder;

    /** @var list<MetricDefinition> */
    private readonly array $allDefinitions;

    /** @var list<MetricDefinition> */
    private readonly array $globalDefinitions;

    public function __construct(
        private readonly FileDiscoveryInterface $defaultDiscovery,
        private readonly CollectionOrchestratorInterface $collectionOrchestrator,
        private readonly CompositeCollector $compositeCollector,
        private readonly RuleExecutorInterface $ruleExecutor,
        private readonly ConfigurationProviderInterface $configurationProvider,
        private readonly GlobalCollectorRunner $globalCollectorRunner,
        ?DependencyGraphBuilder $graphBuilder = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?ProfilerHolder $profilerHolder = null,
    ) {
        $this->graphBuilder = $graphBuilder ?? new DependencyGraphBuilder();

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

    public function analyze(string|array $paths, ?FileDiscoveryInterface $discovery = null): AnalysisResult
    {
        $startTime = microtime(true);
        $profiler = $this->profilerHolder?->get();

        $profiler?->start('analysis', 'pipeline');

        $this->logger->info('Starting analysis', [
            'paths' => \is_array($paths) ? $paths : [$paths],
        ]);

        $repository = new InMemoryMetricRepository();
        $discovery ??= $this->defaultDiscovery;

        // Phase 1: Discovery
        $profiler?->start('discovery', 'pipeline');
        $normalizedPaths = \is_array($paths) ? array_values($paths) : $paths;
        $files = iterator_to_array($discovery->discover($normalizedPaths), false);
        $profiler?->stop('discovery');

        $this->logger->info('Discovered files', ['count' => \count($files)]);

        // Phase 2: Collection (metrics + dependencies in single AST traversal)
        $phaseStartTime = microtime(true);
        $this->logger->debug('Starting collection phase', ['files' => \count($files)]);

        $profiler?->start('collection', 'pipeline');
        $collectionResult = $this->collectionOrchestrator->collect($files, $repository);
        $profiler?->stop('collection');

        $collectionTime = microtime(true) - $phaseStartTime;
        $this->logger->info('Collection completed', [
            'processed' => $collectionResult->filesAnalyzed,
            'errors' => $collectionResult->filesSkipped,
            'dependencies' => \count($collectionResult->dependencies),
            'duration' => \sprintf('%.2fs', $collectionTime),
        ]);

        // Phase 2.5: Build dependency graph from collected dependencies
        $this->logger->debug('Building dependency graph', [
            'dependencies' => \count($collectionResult->dependencies),
        ]);
        $profiler?->start('dependency', 'pipeline');
        $graph = $this->graphBuilder->build($collectionResult->dependencies);
        $profiler?->stop('dependency');

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

        // Phase 3.7: Detect circular dependencies
        $profiler?->start('cycles', 'pipeline');
        $cycles = (new CircularDependencyDetector())->detect($graph);
        $profiler?->stop('cycles');

        // Phase 4: Rule execution
        $phaseStartTime = microtime(true);
        $this->logger->debug('Starting analysis phase');

        $profiler?->start('rules', 'pipeline');
        $context = new AnalysisContext(
            $repository,
            $this->configurationProvider->getRuleOptions(),
            $graph,
            ['cycles' => $cycles],
        );
        $violations = $this->ruleExecutor->execute($context);
        $profiler?->stop('rules');

        $analysisTime = microtime(true) - $phaseStartTime;
        $this->logger->info('Analysis completed', [
            'violations' => \count($violations),
            'duration' => \sprintf('%.2fs', $analysisTime),
        ]);

        // Build result
        $duration = microtime(true) - $startTime;

        $this->logger->info('Analysis complete', [
            'total_duration' => \sprintf('%.2fs', $duration),
            'violations' => \count($violations),
            'files_analyzed' => $collectionResult->filesAnalyzed,
            'files_skipped' => $collectionResult->filesSkipped,
        ]);

        $profiler?->stop('analysis');

        return new AnalysisResult(
            violations: $violations,
            filesAnalyzed: $collectionResult->filesAnalyzed,
            filesSkipped: $collectionResult->filesSkipped,
            duration: $duration,
            metrics: $repository,
        );
    }

}
