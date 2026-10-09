<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Pipeline;

use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluator;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MeasurementAggregationInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryFactoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveSweepScope;
use Qualimetrix\Analysis\Policy\Inline\Contract\DirectiveObservations;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionPhaseOutput;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingFailureKind;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectFilesInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\SkippedEntry;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailure;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DirectiveAuditInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DirectiveAuditReport;
use Qualimetrix\Analysis\Run\Contract\Pipeline\MeasuredRunResult;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeAudit;
use Qualimetrix\Analysis\Run\InlineDirectiveRun;
use Qualimetrix\Analysis\Run\RuleProducerPreparation;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\ClassNameSpelling;
use Qualimetrix\Core\Symbol\MixedSpelling;
use Qualimetrix\Core\Symbol\SymbolPath;

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
final class AnalysisPipeline implements AnalysisPipelineInterface, DirectiveAuditInterface
{
    private readonly DependencyGraphBuilderInterface $graphBuilder;

    public function __construct(
        private readonly ProjectFilesInterface $projectFiles,
        private readonly UnmatchedExcludeAudit $unmatchedExcludeAudit,
        private readonly CollectionOrchestratorInterface $collectionOrchestrator,
        private readonly RuleExecutionInterface $ruleExecutor,
        private readonly RuleProducerPreparation $ruleProducerPreparation,
        private readonly InlineDirectiveRun $inlineDirectiveRun,
        private readonly MeasurementAggregationInterface $measurementAggregation,
        private readonly ComputedMetricEvaluator $computedMetricEvaluation,
        DependencyGraphBuilderInterface $graphBuilder,
        private readonly MetricRepositoryFactoryInterface $repositoryFactory,
        private readonly ProfilerInterface $profiler,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->graphBuilder = $graphBuilder;
    }

    public function analyze(RunConfiguration $configuration): AnalysisResult
    {
        $startTime = hrtime(true);
        [$prepared, $measuredScope] = $this->preparedRun($configuration);
        $latePublished = $this->latePublishedFindings($prepared);
        $duration = (hrtime(true) - $startTime) / 1e9;

        $this->logger->info('Analysis complete', [
            'total_duration' => \sprintf('%.2fs', $duration),
            'violations' => \count($prepared->ruleExecution->published) + \count($latePublished),
            'files_analyzed' => $prepared->coverage->analyzedFilesCount(),
            'files_skipped' => $prepared->coverage->skippedFilesCount(),
        ]);

        return AnalysisResult::fromRun(
            measured: new MeasuredRunResult(
                repository: $prepared->context->metrics,
                coverage: $prepared->coverage,
                namespaceTree: $prepared->namespaceTree,
                projectScope: $measuredScope,
                duration: $duration,
                subjectCoverage: $prepared->subjectCoverage,
            ),
            directives: new DirectiveObservations(
                suppressions: $prepared->collection->suppressions,
                thresholdOverrides: $prepared->collection->thresholdOverrides,
            ),
            ruleExecution: $prepared->ruleExecution,
            latePublished: $latePublished,
            computedMetricEvaluation: $prepared->computedMetricEvaluation,
        );
    }

    /**
     * The audit half of {@see DirectiveAuditInterface}, which states what a
     * verdict is relative to and what it does not measure.
     *
     * Everything below is the wiring: one prepared run, both halves of the
     * question asked against it, and one list in the order an author reads a
     * tree.
     */
    public function auditDirectives(
        RunConfiguration $configuration,
        DirectiveSweepScope $sweep = DirectiveSweepScope::Narrow,
    ): DirectiveAuditReport {
        [$prepared, $measuredScope] = $this->preparedRun($configuration);

        // What the rules produced, and nothing assembled after them. The
        // channel a run assembles late — `annotation.unused-directive` — used
        // to be spliced in here, because a suppression aimed at it came out
        // inert while `check` showed it silencing findings. No suppression can
        // aim at it now, so the splice can no longer move a verdict; what it
        // still moved was `producedFindings`, which would then have counted a
        // channel no verdict was judged against.
        $produced = $prepared->ruleExecution->produced;

        $verdicts = $this->inlineDirectiveRun->verdicts(
            $produced,
            $prepared->ruleExecution->levelActivity,
            $prepared->subjectCoverage,
            $prepared->context,
            $this->ruleExecutor,
            $prepared->ruleExecution,
            $sweep,
        );

        return new DirectiveAuditReport(
            verdicts: $verdicts,
            coverage: $prepared->coverage,
            producedFindings: \count($produced),
            sweep: $sweep,
            projectScope: $measuredScope,
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
    /** @return array{PreparedRun, \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement} */
    private function preparedRun(RunConfiguration $configuration): array
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
        $discoveredFiles = $this->projectFiles->discover($configuration);
        $measuredScope = $configuration->projectScope->withDiscoveredFiles($discoveredFiles);
        $files = $discoveredFiles->eligibleFiles;
        $generatedExcludedFiles = $discoveredFiles->generatedExcludedFiles;

        $eligiblePaths = [];
        $publishedByInput = [];
        foreach ($files as $file) {
            $input = PathFactory::fromCliArgument($file->getPathname(), $configuration->projectRoot);
            $published = PathFactory::published($input, $configuration->projectRoot);
            $eligiblePaths[] = $published;
            $publishedByInput[$input->value()] = $published;
        }
        $skippedFailures = array_map(
            static fn(SkippedEntry $skip): AnalysisFailure => new AnalysisFailure(
                $skip->relativeTo($configuration->projectRoot),
                $skip->reason,
                $skip->detail,
            ),
            $discoveredFiles->skippedEntries,
        );

        $profiler->stop('discovery');

        $this->logger->info('Discovered files', ['count' => $discoveredFiles->discoveredCount]);

        if ($generatedExcludedFiles !== []) {
            $this->logger->info('Skipped @generated files', ['count' => \count($generatedExcludedFiles)]);
        }

        // Phase 2: Collection (metrics + dependencies in single AST traversal)
        $phaseStartTime = hrtime(true);
        $this->logger->debug('Starting collection phase', ['files' => \count($files)]);

        $profiler->start('collection', 'pipeline');
        $collectionResult = $this->collectionOrchestrator->collect($files, $repository, $configuration->projectRoot);
        $profiler->stop('collection');

        $collectionTime = (hrtime(true) - $phaseStartTime) / 1e9;
        $this->logger->info('Collection completed', [
            'processed' => $collectionResult->filesAnalyzed,
            'errors' => $collectionResult->filesSkipped,
            'dependencies' => \count($collectionResult->dependencies),
            'duration' => \sprintf('%.2fs', $collectionTime),
        ]);

        // Phase 2.5: Build dependency graph from collected dependencies.
        // The raw dependency list is not released here: the same
        // CollectionPhaseOutput carries the suppressions and counters the rest
        // of the run needs, so it stays reachable until the run ends. An
        // `unset()` of a second name for it used to claim otherwise.
        $this->logger->debug('Building dependency graph', [
            'dependencies' => \count($collectionResult->dependencies),
        ]);
        $profiler->start('dependency', 'pipeline');
        $graphBuild = $this->graphBuilder->build(
            $collectionResult->dependencies,
            $collectionResult->classLikeDeclarations,
        );
        $graph = $graphBuild->graph;
        $profiler->stop('dependency');
        $this->reportMixedSpellings([...$repository->mixedSpellings(), ...$graphBuild->mixedSpellings]);

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
        $computedMetricEvaluation = $this->computedMetricEvaluation->evaluate($repository, $collectionResult->filesAnalyzed);

        // Phase 5: Circular dependency preparation.
        $this->ruleProducerPreparation->prepareCircularDependencies(
            $graph,
            $profiler,
        );

        // Phase 6: File-set inspection.
        $inspectionFailures = $this->ruleProducerPreparation->inspectFiles(
            $files,
            $configuration->projectRoot,
            $publishedByInput,
        );

        // Phase 6.5: hand this run's inline directives to their owning
        // capability, so the rule that reports on them reads prepared state
        // rather than receiving it through the shared analysis context.
        $this->inlineDirectiveRun->prepare(
            $collectionResult->suppressions,
            $collectionResult->thresholdOverrides,
            $collectionResult->thresholdDiagnostics,
        );

        // Phase 7: Rule execution.
        $phaseStartTime = hrtime(true);
        $this->logger->debug('Starting analysis phase');

        $profiler->start('rules', 'pipeline');
        $context = new AnalysisContext(
            metrics: $repository,
            dependencyGraph: $graph,
            namespaceTree: $namespaceTree,
            thresholdOverrides: $collectionResult->thresholdOverrides,
            projectScope: $measuredScope->judgement(),
        );
        $ruleExecution = $this->ruleExecutor->execute($context);
        $unmatchedTypeWarning = $this->ruleProducerPreparation->unmatchedTypeWarning(
            $measuredScope->judgement(),
            $this->ruleExecutor->publication(),
        );
        if ($unmatchedTypeWarning !== null) {
            $this->logger->warning($unmatchedTypeWarning);
        }
        $profiler->stop('rules');

        $analysisTime = (hrtime(true) - $phaseStartTime) / 1e9;
        $this->logger->info('Analysis completed', [
            'violations' => \count($ruleExecution->published),
            'duration' => \sprintf('%.2fs', $analysisTime),
        ]);

        $profiler->stop('analysis');

        $coverage = self::buildCoverage(
            $eligiblePaths,
            $generatedExcludedFiles,
            $collectionResult,
            $skippedFailures,
            $discoveredFiles->namedExcluded,
            $inspectionFailures,
        );
        $subjectCoverage = SubjectCoverageFacts::fromMeasured(
            $measuredScope->judgement(),
            $coverage->analyzedFiles,
            array_map(static fn(AnalysisFailure $failure): RelativePath => $failure->path, $coverage->failures),
        );

        return [new PreparedRun(
            namespaceTree: $namespaceTree,
            collection: $collectionResult,
            context: $context,
            ruleExecution: $ruleExecution,
            coverage: $coverage,
            subjectCoverage: $subjectCoverage,
            unmatchedExcludeFindings: $this->unmatchedExcludeAudit->findings($measuredScope->judgement(), $configuration->projectRoot),
            computedMetricEvaluation: $computedMetricEvaluation,
        ), $measuredScope];
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
     * per-rule `suppress_namespaces` / `suppress_namespace_channels` /
     * `suppress_paths` ledger and the per-finding channel selection — decisions
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
     * **What is asked and what is reported are still two questions.** The
     * verdict is reached over `produced`; whether the verdict is *published*
     * obeys the same channel selection as every other finding, asked through
     * {@see RuleExecutionInterface::publishable()}. Being assembled after
     * `execute()` used to mean being assembled past that filter, which made
     * `--disable-rule annotation.unused-directive` inert and let an
     * `--only-rule` naming a sibling channel publish this one.
     *
     * @return list<Finding>
     */
    private function latePublishedFindings(PreparedRun $prepared): array
    {
        $ruleExecution = $prepared->ruleExecution;

        return $this->ruleExecutor->publishable([
            ...$this->inlineDirectiveRun->usageFindings(
                $ruleExecution->produced,
                $ruleExecution->levelActivity,
                $prepared->subjectCoverage,
            ),
            // The second channel assembled outside `execute()`, and through
            // the same `publishable()` for the same reason: an exclude pattern
            // that removed nothing is a fact about this run's own input, which
            // no rule can see, but the report it lands in obeys
            // `--disable-rule`, `--only-rule`, the baseline and `--fail-on`
            // like every other finding.
            ...$prepared->unmatchedExcludeFindings,
        ]);

    }

    /**
     * @param list<RelativePath> $eligiblePaths
     * @param list<RelativePath> $generatedExcludedFiles
     * @param list<AnalysisFailure> $skippedFailures published during discovery
     * @param list<RelativePath> $namedExcluded
     * @param list<AnalysisFailure> $inspectionFailures
     */
    private static function buildCoverage(
        array $eligiblePaths,
        array $generatedExcludedFiles,
        CollectionPhaseOutput $collectionResult,
        array $skippedFailures,
        array $namedExcluded,
        array $inspectionFailures,
    ): AnalysisCoverage {
        $failures = array_map(
            static function ($failure): AnalysisFailure {
                return new AnalysisFailure(
                    $failure->filePath,
                    match ($failure->failureKind()) {
                        FileProcessingFailureKind::Parse => AnalysisFailureKind::Parse,
                        FileProcessingFailureKind::UnreadableFile => AnalysisFailureKind::UnreadableFile,
                        FileProcessingFailureKind::Processing => AnalysisFailureKind::Processing,
                    },
                    $failure->error(),
                );
            },
            $collectionResult->failures,
        );

        $collectionFailurePaths = [];
        foreach ($failures as $failure) {
            $collectionFailurePaths[$failure->path->value()] = true;
        }

        $lateFailures = [];
        foreach ($inspectionFailures as $failure) {
            $key = $failure->path->value();
            if (!isset($collectionFailurePaths[$key])) {
                $lateFailures[$key] ??= $failure;
            }
        }

        $analyzed = array_values(array_filter(
            $collectionResult->analyzedFiles,
            static fn(RelativePath $path): bool => !isset($lateFailures[$path->value()]),
        ));

        $coverage = new AnalysisCoverage(
            $analyzed,
            $generatedExcludedFiles,
            [...$failures, ...array_values($lateFailures)],
            $namedExcluded,
        );

        // A skipped entry is a discovered path with a terminal state, so it
        // belongs on both sides of the invariant below — not only in the
        // coverage it is recorded in.
        $skippedPaths = [];
        foreach ($skippedFailures as $skip) {
            $skippedPaths[] = $skip->path;
            $coverage = $coverage->withSkipped($skip->path, $skip->kind, $skip->message);
        }

        self::assertCoverageMatchesDiscovery($coverage, [...$eligiblePaths, ...$skippedPaths]);

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

    /** @param list<MixedSpelling> $mixedSpellings */
    private function reportMixedSpellings(array $mixedSpellings): void
    {
        $reported = [];
        foreach ($mixedSpellings as $mixed) {
            $key = ClassNameSpelling::fold($mixed->canonical);
            if (isset($reported[$key])) {
                continue;
            }
            $reported[$key] = true;
            $this->logger->warning(\sprintf(
                'mixed spelling: %s → %s',
                implode(', ', $mixed->spellings),
                $mixed->canonical,
            ));
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
        foreach ($repository->allLogicalClasses() as $classSymbol) {
            $paths[] = $classSymbol->symbolPath;
        }

        return $paths;
    }
}
