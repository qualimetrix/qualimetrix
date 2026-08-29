<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Pipeline;

use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluator;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MeasurementAggregationInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryFactoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionPhaseOutput;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingFailureKind;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailure;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Analysis\Run\Discovery\AnalysisFileDiscovery;
use Qualimetrix\Analysis\Run\RuleProducerPreparation;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use SplFileInfo;

/**
 * Main analysis pipeline orchestrator.
 *
 * Coordinates all phases of static runtime:
 * 1. Discovery - Find PHP files to analyze
 * 2. Collection - Parse files and collect metrics + dependencies (single AST traversal)
 * 3. Build dependency graph from collected dependencies
 * 4. Measurement aggregation and global reaggregation
 * 5. Computed metric evaluation
 * 6. Circular dependency preparation
 * 7. File-set inspection
 * 8. Rule execution
 */
final class AnalysisPipeline implements AnalysisPipelineInterface
{
    private readonly DependencyGraphBuilderInterface $graphBuilder;

    public function __construct(
        private readonly AnalysisFileDiscovery $analysisFileDiscovery,
        private readonly CollectionOrchestratorInterface $collectionOrchestrator,
        private readonly RuleExecutionInterface $ruleExecutor,
        private readonly RuleProducerPreparation $ruleProducerPreparation,
        private readonly MeasurementAggregationInterface $measurementAggregation,
        private readonly ComputedMetricEvaluator $computedMetricEvaluation,
        DependencyGraphBuilderInterface $graphBuilder,
        private readonly MetricRepositoryFactoryInterface $repositoryFactory,
        private readonly ProfilerInterface $profiler,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->graphBuilder = $graphBuilder;
    }

    public function analyze(RunConfiguration $configuration, ?FileDiscoveryInterface $discovery = null): AnalysisResult
    {
        $startTime = microtime(true);
        $profiler = $this->profiler;

        $profiler->start('analysis', 'pipeline');

        $pathList = $configuration->paths;

        $this->logger->info('Starting analysis', [
            'paths' => array_map(static fn(AbsolutePath $p): string => $p->value(), $pathList),
        ]);

        $repository = $this->repositoryFactory->create();
        // Phase 1: Discovery
        $profiler->start('discovery', 'pipeline');
        $discoveredFiles = $this->analysisFileDiscovery->discover(
            $pathList,
            $configuration->projectRoot,
            $configuration->generatedFilePolicy,
            $discovery,
        );
        $files = $discoveredFiles->eligibleFiles;
        $generatedExcludedFiles = $discoveredFiles->generatedExcludedFiles;

        $profiler->stop('discovery');

        $this->logger->info('Discovered files', ['count' => $discoveredFiles->discoveredCount]);

        if ($generatedExcludedFiles !== []) {
            $this->logger->info('Skipped @generated files', ['count' => \count($generatedExcludedFiles)]);
        }

        // Phase 2: Collection (metrics + dependencies in single AST traversal)
        $phaseStartTime = microtime(true);
        $this->logger->debug('Starting collection phase', ['files' => \count($files)]);

        $profiler->start('collection', 'pipeline');
        $collectionOutput = $this->collectionOrchestrator->collect($files, $repository, $configuration->projectRoot);
        $collectionResult = $collectionOutput;
        $profiler->stop('collection');

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
        $profiler->start('dependency', 'pipeline');
        $graph = $this->buildDependencyGraph(
            $collectionOutput->dependencies,
            $repository,
        );
        unset($collectionOutput); // Free raw dependencies — no longer needed
        $profiler->stop('dependency');

        // Phase 2.6: prepare Architecture-owned layer policy from this run's
        // graph and class universe. Run selects the producer rule through its
        // public contract and never receives Architecture state.
        $this->ruleProducerPreparation->prepareArchitecture(
            $graph,
            self::collectClassPaths($repository),
            $profiler,
        );

        // Phase 3: Measurement aggregation and global reaggregation.
        $namespaceTree = $this->measurementAggregation->aggregate($repository, $graph);

        // Phase 4: Computed metric evaluation.
        $this->computedMetricEvaluation->evaluate($repository, $collectionResult->filesAnalyzed);

        // Phase 5: Circular dependency preparation.
        $this->ruleProducerPreparation->prepareCircularDependencies(
            $graph,
            $profiler,
        );

        // Phase 6: File-set inspection.
        $this->ruleProducerPreparation->inspectFiles($files, $configuration->projectRoot);

        // Phase 6.5: hand this run's inline directives to their owning
        // capability, so the rule that reports on them reads prepared state
        // rather than receiving it through the shared analysis context.
        $this->ruleProducerPreparation->prepareInlineDirectives(
            $collectionResult->suppressions,
            $collectionResult->thresholdOverrides,
            $collectionResult->thresholdDiagnostics,
        );

        // Phase 7: Rule execution.
        $phaseStartTime = microtime(true);
        $this->logger->debug('Starting analysis phase');

        $profiler->start('rules', 'pipeline');
        $ruleExecution = $this->executeRulesForRun($repository, $graph, $namespaceTree, $collectionResult);
        $findings = $this->reportedFindings($ruleExecution);
        $profiler->stop('rules');

        $analysisTime = microtime(true) - $phaseStartTime;
        $this->logger->info('Analysis completed', [
            'violations' => \count($findings),
            'duration' => \sprintf('%.2fs', $analysisTime),
        ]);

        // Build result
        $duration = microtime(true) - $startTime;
        $eligiblePaths = array_map(
            static fn(SplFileInfo $file): RelativePath => PathFactory::bestEffortRelative(
                $file->getPathname(),
                $configuration->projectRoot,
            ),
            $files,
        );
        $coverage = self::buildCoverage($eligiblePaths, $generatedExcludedFiles, $collectionResult);

        $this->logger->info('Analysis complete', [
            'total_duration' => \sprintf('%.2fs', $duration),
            'violations' => \count($findings),
            'files_analyzed' => $collectionResult->filesAnalyzed,
            'files_skipped' => $coverage->skippedFilesCount(),
        ]);

        $profiler->stop('analysis');

        return new AnalysisResult(
            findings: $findings,
            duration: $duration,
            metrics: $repository,
            coverage: $coverage,
            suppressions: $collectionResult->suppressions,
            namespaceTree: $namespaceTree,
            thresholdOverrides: $collectionResult->thresholdOverrides,
            ruleExecution: $ruleExecution,
        );
    }

    /** @param list<Dependency> $dependencies */
    private function buildDependencyGraph(
        array $dependencies,
        MetricRepositoryInterface $repository,
    ): DependencyGraphInterface {
        return $this->graphBuilder->build($dependencies, self::collectLogicalClassPaths($repository));
    }

    private function executeRulesForRun(
        MetricRepositoryInterface $repository,
        DependencyGraphInterface $graph,
        NamespaceTree $namespaceTree,
        CollectionPhaseOutput $collectionResult,
    ): RuleExecutionResult {
        $context = new AnalysisContext(
            metrics: $repository,
            dependencyGraph: $graph,
            namespaceTree: $namespaceTree,
            thresholdOverrides: $collectionResult->thresholdOverrides,
        );

        return $this->ruleExecutor->execute($context);
    }

    /**
     * What {@see AnalysisResult::$findings} carries: `$ruleExecution`'s published
     * findings plus the directive-usage audit below, which is not part of rule
     * execution proper and therefore not part of `$ruleExecution` itself.
     *
     * The directive-usage half of the inline-directive report can only be
     * answered once every rule has produced its findings — a suppression is
     * stale exactly when nothing it covers was reported. The channel identity
     * and the wording stay with the owning capability; Run only decides when
     * to ask.
     *
     * @return list<Finding>
     */
    private function reportedFindings(RuleExecutionResult $ruleExecution): array
    {
        $findings = $ruleExecution->published;
        $unused = $this->ruleProducerPreparation->auditInlineDirectives($findings);

        return $unused === [] ? $findings : array_merge($findings, $unused);
    }

    /** @return list<LogicalClassPath> */
    private static function collectLogicalClassPaths(MetricRepositoryInterface $repository): array
    {
        $classes = [];
        foreach ($repository->allLogicalClasses() as $info) {
            $logicalClass = $info->subject?->logicalClassPath();
            if ($logicalClass !== null) {
                $classes[$logicalClass->toCanonical()] = $logicalClass;
            }
        }

        return array_values($classes);
    }

    /**
     * @param list<RelativePath> $eligiblePaths
     * @param list<RelativePath> $generatedExcludedFiles
     */
    private static function buildCoverage(
        array $eligiblePaths,
        array $generatedExcludedFiles,
        CollectionPhaseOutput $collectionResult,
    ): AnalysisCoverage {
        $failures = array_map(
            static function ($failure): AnalysisFailure {
                return new AnalysisFailure(
                    $failure->filePath,
                    match ($failure->failureKind()) {
                        FileProcessingFailureKind::Parse => AnalysisFailureKind::Parse,
                        FileProcessingFailureKind::Processing => AnalysisFailureKind::Processing,
                    },
                    $failure->error(),
                );
            },
            $collectionResult->failures,
        );

        $coverage = new AnalysisCoverage(
            $collectionResult->analyzedFiles,
            $generatedExcludedFiles,
            $failures,
        );

        self::assertCoverageMatchesDiscovery($coverage, $eligiblePaths);

        return $coverage;
    }

    /** @param list<RelativePath> $eligiblePaths */
    private static function assertCoverageMatchesDiscovery(
        AnalysisCoverage $coverage,
        array $eligiblePaths,
    ): void {
        $expected = array_map(static fn(RelativePath $path): string => $path->value(), $eligiblePaths);
        sort($expected);

        $actual = array_map(
            static fn(RelativePath $path): string => $path->value(),
            $coverage->analyzedFiles,
        );
        foreach ($coverage->failures as $failure) {
            $actual[] = $failure->path->value();
        }
        sort($actual);

        if ($actual !== $expected) {
            throw new LogicException('Collection terminal states do not match the discovered analysis paths');
        }
    }

    /**
     * Collects the {@see SymbolPath} for every class symbol recorded in the
     * metric repository — the input set for architecture template expansion.
     *
     * @return list<SymbolPath>
     */
    private static function collectClassPaths(MetricRepositoryInterface $repository): array
    {
        $paths = [];
        foreach ($repository->all(SymbolLevel::Class_) as $classSymbol) {
            $paths[] = $classSymbol->symbolPath;
        }

        return $paths;
    }
}
