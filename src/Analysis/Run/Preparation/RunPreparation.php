<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Preparation;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Channel\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RulePublicationInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingFailureKind;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Coverage\AnalysisFailure;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectFilesInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\SkippedEntry;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeAudit;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeRule;
use Qualimetrix\Analysis\Run\InlineDirectiveRun;
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
 * @qmx-threshold coupling.cbo 34 -- Preparation combines discovery, collection retry, measured graph and one rule context. The execution and measurement subjects were separated; further splitting these connected inputs would move the same dependency set to another coordinator.
 * @qmx-threshold coupling.instability 0.939395 -- The preparation coordinator calls the participants needed for one measured context after measurement and publication were separated; its outward protocol retains a finite instability boundary.
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
        $discovery = $this->discover($configuration);
        $discoveryPopulation = $this->unmatchedExcludeAudit->population(
            $discovery->measuredScope->judgement(),
            $populationPublication,
            new FindingChannel(UnmatchedExcludeRule::NAME),
            UnmatchedExcludeRule::channelDeclarations()[UnmatchedExcludeRule::NAME],
        );
        $collection = $this->collectWithTraitRetry($discovery->files, $discovery->publishedByInput, $configuration);
        $namespaceTree = $collection->measurements->namespaceTree;
        $computedMetricEvaluation = $collection->measurements->computedMetricEvaluation;

        // Phase 5: Circular dependency preparation.
        $this->ruleProducerPreparation->prepareCircularDependencies(
            $collection->graph,
            $profiler,
        );

        // Phase 6: File-set inspection.
        $inspectionFailures = $this->ruleProducerPreparation->inspectFiles(
            $collection->files,
            $configuration->projectRoot,
            $discovery->publishedByInput,
        );

        // Phase 6.5: hand this run's inline directives to their owning
        // capability, so the rule that reports on them reads prepared state
        // rather than receiving it through the shared analysis context.
        $this->inlineDirectiveRun->prepare(
            $collection->suppressions,
            $collection->thresholdOverrides,
            $collection->thresholdDiagnostics,
        );

        $context = new AnalysisContext(
            metrics: $collection->repository,
            dependencyGraph: $collection->graph,
            namespaceTree: $namespaceTree,
            thresholdOverrides: $collection->thresholdOverrides,
            projectScope: $discovery->measuredScope->judgement(),
        );
        $unmatchedTypeWarning = $this->ruleProducerPreparation->unmatchedTypeWarning(
            $context->projectScope,
            $populationPublication,
        );
        $ruleExecution = $this->executeRules($context, $unmatchedTypeWarning);

        $profiler->stop('analysis');

        $coverage = $discovery->coverage(
            analyzedFiles: $collection->analyzedFiles,
            collectionFailures: $collection->failures,
            inspectionFailures: $inspectionFailures,
        );
        $subjectCoverage = SubjectCoverageFacts::fromMeasured(
            $discovery->measuredScope->judgement(),
            $coverage->analyzedFiles,
            array_map(static fn(AnalysisFailure $failure): RelativePath => $failure->path, $coverage->failures),
        );

        return [new PreparedRun(
            namespaceTree: $namespaceTree,
            suppressions: $collection->suppressions,
            thresholdOverrides: $collection->thresholdOverrides,
            context: $context,
            ruleExecution: $ruleExecution,
            coverage: $coverage,
            subjectCoverage: $subjectCoverage,
            unmatchedExcludeFindings: $this->unmatchedExcludeAudit->findings($discovery->measuredScope->judgement(), $configuration->projectRoot),
            computedMetricEvaluation: $computedMetricEvaluation,
            discoveryPopulation: $discoveryPopulation,
            populationPublication: $populationPublication,
        ), $discovery->measuredScope];
    }

    private function discover(RunConfiguration $configuration): DiscoveryResult
    {
        $this->profiler->start('discovery', 'pipeline');
        $discoveredFiles = $this->projectFiles->discover($configuration);
        $measuredScope = $configuration->projectScope->withDiscoveredFiles($discoveredFiles);
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

        return new DiscoveryResult(
            measuredScope: $measuredScope,
            files: $discoveredFiles->eligibleFiles,
            eligiblePaths: $eligiblePaths,
            generatedExcludedFiles: $discoveredFiles->generatedExcludedFiles,
            publishedByInput: $publishedByInput,
            skippedFailures: $skippedFailures,
            namedExcluded: $discoveredFiles->namedExcluded,
        );
    }

    /**
     * @param list<SplFileInfo> $files
     * @param array<string, RelativePath> $publishedByInput
     */
    private function collectWithTraitRetry(array $files, array $publishedByInput, RunConfiguration $configuration): CollectionResult
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

                return new CollectionResult(
                    repository: $repository,
                    graph: $graph,
                    measurements: $measurements,
                    analyzedFiles: $analyzedFiles,
                    failures: $collectionFailures,
                    suppressions: $suppressions,
                    thresholdOverrides: $thresholdOverrides,
                    thresholdDiagnostics: $thresholdDiagnostics,
                    files: $files,
                );
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

    private function executeRules(AnalysisContext $context, ?string $unmatchedTypeWarning): \Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult
    {
        $phaseStartTime = hrtime(true);
        $this->logger->debug('Starting analysis phase');
        $this->profiler->start('rules', 'pipeline');
        $execution = $this->ruleExecutor->execute($context);
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
