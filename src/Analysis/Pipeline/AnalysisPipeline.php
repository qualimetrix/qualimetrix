<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Pipeline;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Collection\Dependency\DependencyGraphBuilder;
use Qualimetrix\Analysis\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Discovery\GeneratedFileFilter;
use Qualimetrix\Analysis\Repository\DefaultMetricRepositoryFactory;
use Qualimetrix\Analysis\Repository\MetricRepositoryFactoryInterface;
use Qualimetrix\Analysis\RuleExecution\RuleExecutorInterface;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Core\Rule\AnalysisContext;

/**
 * Main analysis pipeline orchestrator.
 *
 * Coordinates all phases of static analysis:
 * 1. Discovery - Find PHP files to analyze
 * 2. Collection - Parse files and collect metrics + dependencies (single AST traversal)
 * 3. Build dependency graph from collected dependencies
 * 4. Enrichment - Aggregation, global collectors, computed metrics, circular deps, duplication
 * 5. Rule execution - Run analysis rules
 */
final class AnalysisPipeline implements AnalysisPipelineInterface
{
    private readonly DependencyGraphBuilder $graphBuilder;

    public function __construct(
        private readonly FileDiscoveryInterface $defaultDiscovery,
        private readonly CollectionOrchestratorInterface $collectionOrchestrator,
        private readonly RuleExecutorInterface $ruleExecutor,
        private readonly ConfigurationProviderInterface $configurationProvider,
        private readonly MetricEnricher $metricEnricher,
        private readonly MetricRepositoryFactoryInterface $repositoryFactory = new DefaultMetricRepositoryFactory(),
        ?DependencyGraphBuilder $graphBuilder = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?ProfilerHolder $profilerHolder = null,
    ) {
        $this->graphBuilder = $graphBuilder ?? new DependencyGraphBuilder();
    }

    public function analyze(string|array $paths, ?FileDiscoveryInterface $discovery = null): AnalysisResult
    {
        $startTime = microtime(true);
        $profiler = $this->profilerHolder?->get();

        $profiler?->start('analysis', 'pipeline');

        $this->logger->info('Starting analysis', [
            'paths' => \is_array($paths) ? $paths : [$paths],
        ]);

        $repository = $this->repositoryFactory->create();
        $discovery ??= $this->defaultDiscovery;

        // Phase 1: Discovery
        $profiler?->start('discovery', 'pipeline');
        $normalizedPaths = \is_array($paths) ? array_values($paths) : $paths;
        $files = array_values(iterator_to_array($discovery->discover($normalizedPaths), true));

        // Filter out @generated files unless explicitly included
        $config = $this->configurationProvider->getConfiguration();
        $generatedSkipped = 0;
        if (!$config->includeGenerated) {
            $originalCount = \count($files);
            $generatedFilter = new GeneratedFileFilter();
            $files = $generatedFilter->filter($files);
            $generatedSkipped = $originalCount - \count($files);
        }

        $profiler?->stop('discovery');

        $this->logger->info('Discovered files', ['count' => \count($files)]);

        if ($generatedSkipped > 0) {
            $this->logger->info('Skipped @generated files', ['count' => $generatedSkipped]);
        }

        // Phase 2: Collection (metrics + dependencies in single AST traversal)
        $phaseStartTime = microtime(true);
        $this->logger->debug('Starting collection phase', ['files' => \count($files)]);

        $profiler?->start('collection', 'pipeline');
        $collectionOutput = $this->collectionOrchestrator->collect($files, $repository);
        $collectionResult = $collectionOutput->result;
        $profiler?->stop('collection');

        $collectionTime = microtime(true) - $phaseStartTime;
        $this->logger->info('Collection completed', [
            'processed' => $collectionResult->filesAnalyzed,
            'errors' => $collectionResult->filesSkipped,
            'dependencies' => \count($collectionOutput->dependencies),
            'duration' => \sprintf('%.2fs', $collectionTime),
        ]);

        // Phase 2.5: Build dependency graph from collected dependencies
        // Dependencies are consumed here and freed immediately after graph is built
        $this->logger->debug('Building dependency graph', [
            'dependencies' => \count($collectionOutput->dependencies),
        ]);
        $profiler?->start('dependency', 'pipeline');
        $graph = $this->graphBuilder->build($collectionOutput->dependencies);
        unset($collectionOutput); // Free raw dependencies — no longer needed
        $profiler?->stop('dependency');

        // Phases 3-3.8: Enrichment (aggregation, global collectors, computed metrics,
        // circular dependency detection, duplication detection)
        $enrichmentResult = $this->metricEnricher->enrich(
            $repository,
            $graph,
            $files,
            $collectionResult->filesAnalyzed,
        );

        // Phase 4: Rule execution
        $phaseStartTime = microtime(true);
        $this->logger->debug('Starting analysis phase');

        $profiler?->start('rules', 'pipeline');
        $context = new AnalysisContext(
            $repository,
            $this->configurationProvider->getRuleOptions(),
            $graph,
            $enrichmentResult->cycles,
            $enrichmentResult->duplicateBlocks,
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
            suppressions: $collectionResult->suppressions,
        );
    }
}
