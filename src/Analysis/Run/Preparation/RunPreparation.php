<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Preparation;

use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\Channel\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Population\JudgedPopulation;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RulePublicationInterface;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingFailureKind;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Coverage\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Coverage\AnalysisFailure;
use Qualimetrix\Analysis\Run\Contract\Coverage\AnalysisFailureKind;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectFilesInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\SkippedEntry;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeAudit;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeRule;
use Qualimetrix\Analysis\Run\InlineDirectiveRun;
use Qualimetrix\Analysis\Run\Measurement\CompletedMeasurements;
use Qualimetrix\Analysis\Run\Measurement\RunMeasurement;
use Qualimetrix\Analysis\Run\RuleProducerPreparation;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\ClassNameSpelling;
use Qualimetrix\Core\Symbol\MixedSpelling;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\TraitComposition\Contract\TraitCompositionFailure;
use SplFileInfo;

/**
 * Prepares one measured execution context shared by normal reporting and directive audit.
 *
 * @qmx-threshold coupling.cbo 35 -- Preparation combines discovery, collection retry, measured graph and one rule context. The typed immutable ChannelPublication snapshot is shared by discovery and the final PreparedRun; the finite class boundary exposes another dependency.
 * @qmx-threshold coupling.instability 0.941177 -- The typed immutable ChannelPublication snapshot preserves one publication view across discovery and the final PreparedRun; the finite class boundary exposes another outward dependency.
 */
final class RunPreparation
{
    private readonly DependencyGraphBuilderInterface $graphBuilder;

    /**
     * @qmx-threshold code-smell.long-parameter-list 12 -- Preparation participants must share this run and its measured context; report and audit publication are owned by the outer pipeline.
     * @qmx-threshold code-smell.constructor-overinjection 12 -- Preparation participants must share this run and its measured context; report and audit publication are owned by the outer pipeline.
     */
    public function __construct(
        private readonly ProjectFilesInterface $projectFiles,
        private readonly UnmatchedExcludeAudit $unmatchedExcludeAudit,
        private readonly CollectionOrchestratorInterface $collectionOrchestrator,
        private readonly RuleExecutionInterface $ruleExecutor,
        private readonly RulePublicationInterface $rulePublication,
        private readonly RuleProducerPreparation $ruleProducerPreparation,
        private readonly InlineDirectiveRun $inlineDirectiveRun,
        private readonly RunMeasurement $runMeasurement,
        DependencyGraphBuilderInterface $graphBuilder,
        private readonly ProfilerInterface $profiler,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->graphBuilder = $graphBuilder;
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
     *
     * @return array{PreparedRun, \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement}
     */
    public function prepare(RunConfiguration $configuration): array
    {
        $profiler = $this->profiler;

        $profiler->start('analysis', 'pipeline');

        $pathList = $configuration->paths;

        $this->logger->info('Starting analysis', [
            'paths' => array_map(static fn(AbsolutePath $p): string => $p->value(), $pathList),
        ]);

        $populationPublication = $this->rulePublication->publication();
        [
            $measuredScope,
            $discoveryPopulation,
            $files,
            $eligiblePaths,
            $generatedExcludedFiles,
            $publishedByInput,
            $skippedFailures,
            $namedExcluded,
        ] = $this->discover($configuration, $populationPublication);
        [
            $repository,
            $graph,
            $measurements,
            $analyzedFiles,
            $collectionFailures,
            $suppressions,
            $thresholdOverrides,
            $thresholdDiagnostics,
            $files,
        ] = $this->collectWithTraitRetry($files, $publishedByInput, $configuration);
        $namespaceTree = $measurements->namespaceTree;
        $computedMetricEvaluation = $measurements->computedMetricEvaluation;

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
            $suppressions,
            $thresholdOverrides,
            $thresholdDiagnostics,
        );

        $context = new AnalysisContext(
            metrics: $repository,
            dependencyGraph: $graph,
            namespaceTree: $namespaceTree,
            thresholdOverrides: $thresholdOverrides,
            projectScope: $measuredScope->judgement(),
        );
        $ruleExecution = $this->executeRules($context);

        $profiler->stop('analysis');

        $coverage = self::buildCoverage(
            $eligiblePaths,
            $generatedExcludedFiles,
            $analyzedFiles,
            $collectionFailures,
            $skippedFailures,
            $namedExcluded,
            $inspectionFailures,
        );
        $subjectCoverage = SubjectCoverageFacts::fromMeasured(
            $measuredScope->judgement(),
            $coverage->analyzedFiles,
            array_map(static fn(AnalysisFailure $failure): RelativePath => $failure->path, $coverage->failures),
        );

        return [new PreparedRun(
            namespaceTree: $namespaceTree,
            suppressions: $suppressions,
            thresholdOverrides: $thresholdOverrides,
            context: $context,
            ruleExecution: $ruleExecution,
            coverage: $coverage,
            subjectCoverage: $subjectCoverage,
            unmatchedExcludeFindings: $this->unmatchedExcludeAudit->findings($measuredScope->judgement(), $configuration->projectRoot),
            computedMetricEvaluation: $computedMetricEvaluation,
            discoveryPopulation: $discoveryPopulation,
            populationPublication: $populationPublication,
        ), $measuredScope];
    }

    /**
     * @return array{
     *     ProjectScopeMeasurement,
     *     JudgedPopulation,
     *     list<SplFileInfo>,
     *     list<RelativePath>,
     *     list<RelativePath>,
     *     array<string, RelativePath>,
     *     list<AnalysisFailure>,
     *     list<RelativePath>
     * }
     */
    private function discover(RunConfiguration $configuration, ChannelPublication $populationPublication): array
    {
        $this->profiler->start('discovery', 'pipeline');
        $discoveredFiles = $this->projectFiles->discover($configuration);
        $measuredScope = $configuration->projectScope->withDiscoveredFiles($discoveredFiles);
        $discoveryPopulation = $this->unmatchedExcludeAudit->population(
            $measuredScope->judgement(),
            $populationPublication,
            new FindingChannel(UnmatchedExcludeRule::NAME),
            UnmatchedExcludeRule::channelDeclarations()[UnmatchedExcludeRule::NAME],
        );

        $eligiblePaths = [];
        $publishedByInput = [];
        foreach ($discoveredFiles->eligibleFiles as $file) {
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

        $this->profiler->stop('discovery');
        $this->logger->info('Discovered files', ['count' => $discoveredFiles->discoveredCount]);
        if ($discoveredFiles->generatedExcludedFiles !== []) {
            $this->logger->info('Skipped @generated files', ['count' => \count($discoveredFiles->generatedExcludedFiles)]);
        }

        return [
            $measuredScope,
            $discoveryPopulation,
            $discoveredFiles->eligibleFiles,
            $eligiblePaths,
            $discoveredFiles->generatedExcludedFiles,
            $publishedByInput,
            $skippedFailures,
            $discoveredFiles->namedExcluded,
        ];
    }

    /**
     * @param list<SplFileInfo> $files
     * @param array<string, RelativePath> $publishedByInput
     *
     * @return array{
     *     MetricRepositoryInterface,
     *     DependencyGraphInterface,
     *     CompletedMeasurements,
     *     list<RelativePath>,
     *     list<FileProcessingResult>,
     *     array<string, list<Suppression>>,
     *     array<string, list<ThresholdOverride>>,
     *     array<string, list<ThresholdDiagnostic>>,
     *     list<SplFileInfo>
     * }
     */
    private function collectWithTraitRetry(array $files, array $publishedByInput, RunConfiguration $configuration): array
    {
        $compositionFailures = [];
        while (true) {
            $repository = $this->runMeasurement->startRepository();
            try {
                $phaseStartTime = hrtime(true);
                $this->logger->debug('Starting collection phase', ['files' => \count($files)]);

                $this->profiler->start('collection', 'pipeline');
                $collectionResult = $this->collectionOrchestrator->collect($files, $repository, $configuration->projectRoot);
                $this->profiler->stop('collection');

                $this->logPhaseCompleted('Collection', $phaseStartTime, [
                    'processed' => $collectionResult->filesAnalyzed,
                    'errors' => $collectionResult->filesSkipped,
                    'dependencies' => \count($collectionResult->dependencies),
                ]);

                $this->logger->debug('Building dependency graph', [
                    'dependencies' => \count($collectionResult->dependencies),
                ]);
                $this->profiler->start('dependency', 'pipeline');
                $graphBuild = $this->graphBuilder->build(
                    $collectionResult->dependencies,
                    $collectionResult->classLikeDeclarations,
                );
                $graph = $graphBuild->graph;
                $this->profiler->stop('dependency');
                $this->reportMixedSpellings([...$repository->mixedSpellings(), ...$graphBuild->mixedSpellings]);

                $this->ruleProducerPreparation->prepareArchitecture(
                    $graph,
                    self::collectClassPaths($repository),
                    $this->profiler,
                );

                $filesAnalyzed = $collectionResult->filesAnalyzed;
                $analyzedFiles = $collectionResult->analyzedFiles;
                $collectionFailures = [...$collectionResult->failures, ...$compositionFailures];
                $suppressions = $collectionResult->suppressions;
                $thresholdOverrides = $collectionResult->thresholdOverrides;
                $thresholdDiagnostics = $collectionResult->thresholdDiagnostics;
                unset($collectionResult);

                $measurements = $this->runMeasurement->complete($repository, $graph, $filesAnalyzed);

                return [
                    $repository,
                    $graph,
                    $measurements,
                    $analyzedFiles,
                    $collectionFailures,
                    $suppressions,
                    $thresholdOverrides,
                    $thresholdDiagnostics,
                    $files,
                ];
            } catch (TraitCompositionFailure $failure) {
                $declaration = $failure->declaration;
                if ($declaration === null) {
                    throw $failure;
                }
                $remaining = array_values(array_filter(
                    $files,
                    static fn(SplFileInfo $file): bool => !$publishedByInput[PathFactory::fromCliArgument(
                        $file->getPathname(),
                        $configuration->projectRoot,
                    )->value()]->equals($declaration->file),
                ));
                if (\count($remaining) === \count($files)) {
                    throw $failure;
                }
                $compositionFailures[] = FileProcessingResult::failure(
                    $declaration->file,
                    $declaration->logical->toString() . ': ' . $failure->getMessage(),
                    FileProcessingFailureKind::Parse,
                );
                $files = $remaining;
            }
        }
    }

    private function executeRules(AnalysisContext $context): \Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult
    {
        $phaseStartTime = hrtime(true);
        $this->logger->debug('Starting analysis phase');
        $this->profiler->start('rules', 'pipeline');
        $execution = $this->ruleExecutor->execute($context);
        $unmatchedTypeWarning = $this->ruleProducerPreparation->unmatchedTypeWarning(
            $context->projectScope,
            $this->rulePublication->publication(),
        );
        if ($unmatchedTypeWarning !== null) {
            $this->logger->warning($unmatchedTypeWarning);
        }
        $this->profiler->stop('rules');
        $this->logPhaseCompleted('Analysis', $phaseStartTime, [
            'violations' => \count($execution->published),
        ]);

        return $execution;
    }

    /** @param array<string, int> $counts */
    private function logPhaseCompleted(string $phase, int $startTime, array $counts): void
    {
        $this->logger->info($phase . ' completed', [
            ...$counts,
            'duration' => \sprintf('%.2fs', (hrtime(true) - $startTime) / 1e9),
        ]);
    }

    /**
     * @param list<RelativePath> $eligiblePaths
     * @param list<RelativePath> $generatedExcludedFiles
     * @param list<RelativePath> $analyzedFiles
     * @param list<FileProcessingResult> $collectionFailures
     * @param list<AnalysisFailure> $skippedFailures published during discovery
     * @param list<RelativePath> $namedExcluded
     * @param list<AnalysisFailure> $inspectionFailures
     */
    private static function buildCoverage(
        array $eligiblePaths,
        array $generatedExcludedFiles,
        array $analyzedFiles,
        array $collectionFailures,
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
            $collectionFailures,
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
            $analyzedFiles,
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
