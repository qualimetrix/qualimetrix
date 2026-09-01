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
        $prepared = $this->preparedRun($configuration, $discovery);
        $findings = $this->reportedFindings($prepared->ruleExecution);
        $duration = microtime(true) - $startTime;

        $this->logger->info('Analysis complete', [
            'total_duration' => \sprintf('%.2fs', $duration),
            'violations' => \count($findings),
            'files_analyzed' => $prepared->collection->filesAnalyzed,
            'files_skipped' => $prepared->coverage->skippedFilesCount(),
        ]);

        return new AnalysisResult(
            findings: $findings,
            duration: $duration,
            metrics: $prepared->context->metrics,
            coverage: $prepared->coverage,
            suppressions: $prepared->collection->suppressions,
            namespaceTree: $prepared->namespaceTree,
            thresholdOverrides: $prepared->collection->thresholdOverrides,
            ruleExecution: $prepared->ruleExecution,
        );
    }

    /**
     * The second question this pipeline answers about one prepared run: what
     * each inline directive the run carried actually did.
     *
     * It is a second entry point rather than a second operation on
     * {@see AnalysisPipelineInterface}, because the four consumers of that
     * contract analyse and do not audit — the same split
     * {@see \Qualimetrix\Analysis\Run\Contract\Pipeline\DependencyGraphAnalyzerInterface}
     * already makes for the graph. The public contract, and its only reader,
     * arrive with the command.
     *
     * **What a verdict here is relative to.** The analysed scope, and nothing
     * wider: a directive retuning a metric computed over the analysed subgraph
     * — coupling is the standing case — is live over one tree and dead over a
     * subdirectory of it, and neither answer is wrong. There is no "was the
     * whole project analysed" flag to publish, because a resolved
     * {@see RunConfiguration} no longer knows what it was resolved from, so
     * the report states the coverage and the selection it measured under and
     * lets the caller judge. An **incomplete** run — files that failed to
     * parse — is a different thing, and `$coverage` answers it exactly as it
     * does for `analyze()`.
     */
    public function auditDirectives(
        RunConfiguration $configuration,
        ?FileDiscoveryInterface $discovery = null,
    ): DirectiveAuditReport {
        $prepared = $this->preparedRun($configuration, $discovery);
        $produced = $prepared->ruleExecution->produced;

        return new DirectiveAuditReport(
            verdicts: [
                ...$this->ruleProducerPreparation->directiveVerdicts($produced),
                ...$this->ruleProducerPreparation->auditThresholdDirectives(
                    $prepared->context,
                    $this->ruleExecutor,
                    $prepared->ruleExecution,
                ),
            ],
            coverage: $prepared->coverage,
            producedFindings: \count($produced),
        );
    }

    /**
     * Everything both entry points need, run once: the discovered files
     * measured, the capabilities that produce rules prepared, and the rules
     * executed once over the context that came out of it.
     *
     * The step is shared rather than repeated because the audit's whole method
     * is to re-execute rules **on the run's own context**: a second collection
     * would measure a second world, and a difference between two worlds says
     * nothing about a directive.
     */
    private function preparedRun(RunConfiguration $configuration, ?FileDiscoveryInterface $discovery): PreparedRun
    {
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
        $context = new AnalysisContext(
            metrics: $repository,
            dependencyGraph: $graph,
            namespaceTree: $namespaceTree,
            thresholdOverrides: $collectionResult->thresholdOverrides,
        );
        $ruleExecution = $this->ruleExecutor->execute($context);
        $profiler->stop('rules');

        $analysisTime = microtime(true) - $phaseStartTime;
        $this->logger->info('Analysis completed', [
            'violations' => \count($ruleExecution->published),
            'duration' => \sprintf('%.2fs', $analysisTime),
        ]);

        $eligiblePaths = array_map(
            static fn(SplFileInfo $file): RelativePath => PathFactory::bestEffortRelative(
                $file->getPathname(),
                $configuration->projectRoot,
            ),
            $files,
        );

        $profiler->stop('analysis');

        return new PreparedRun(
            namespaceTree: $namespaceTree,
            collection: $collectionResult,
            context: $context,
            ruleExecution: $ruleExecution,
            coverage: self::buildCoverage($eligiblePaths, $generatedExcludedFiles, $collectionResult),
        );
    }

    /** @param list<Dependency> $dependencies */
    private function buildDependencyGraph(
        array $dependencies,
        MetricRepositoryInterface $repository,
    ): DependencyGraphInterface {
        return $this->graphBuilder->build($dependencies, self::collectLogicalClassPaths($repository));
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
     * **The audit is asked about `produced`, not `published`, and the two
     * differ by exactly the wrong thing.** `published` has already lost the
     * per-rule `exclude_namespaces` / `exclude_namespace_channels` /
     * `exclude_paths` ledger and the per-finding channel selection — decisions
     * about what a *report* shows. Judging an annotation by them means a
     * suppression covering a finding the ledger would have dropped anyway is
     * reported as silencing nothing: a statement about configuration dressed
     * up as a statement about the author's annotation.
     *
     * The direction is one-way by construction: `SuppressionFilter::suppressesAny()`
     * is an existential over the finding list, so widening the list can only
     * turn "matched nothing" into "matched something" — the audit can lose a
     * stale report here, never gain one.
     *
     * @return list<Finding>
     */
    private function reportedFindings(RuleExecutionResult $ruleExecution): array
    {
        $unused = $this->ruleProducerPreparation->auditInlineDirectives($ruleExecution->produced);

        return $unused === [] ? $ruleExecution->published : array_merge($ruleExecution->published, $unused);
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
