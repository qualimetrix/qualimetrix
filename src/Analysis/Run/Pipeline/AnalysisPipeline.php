<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Pipeline;

use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfigurationProviderInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluator;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MeasurementAggregationInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryFactoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleMatcher;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionPhaseOutput;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingFailureKind;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailure;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Analysis\Run\Discovery\AnalysisFileDiscovery;
use Qualimetrix\Analysis\Run\Discovery\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\RuleProducerPreparation;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
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
        private readonly TransitionalRuntimeConfigurationProviderInterface $configurationProvider,
        private readonly RuleProducerPreparation $ruleProducerPreparation,
        private readonly MeasurementAggregationInterface $measurementAggregation,
        private readonly ComputedMetricEvaluator $computedMetricEvaluation,
        DependencyGraphBuilderInterface $graphBuilder,
        private readonly MetricRepositoryFactoryInterface $repositoryFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?ProfilerHolder $profilerHolder = null,
    ) {
        $this->graphBuilder = $graphBuilder;
    }

    public function analyze(AbsolutePath|array $paths, ?FileDiscoveryInterface $discovery = null): AnalysisResult
    {
        $startTime = microtime(true);
        $profiler = $this->profilerHolder?->get() ?? ProfilerHolder::get(); // @phpstan-ignore staticMethod.dynamicCall

        $profiler->start('analysis', 'pipeline');

        $pathList = $paths instanceof AbsolutePath ? [$paths] : array_values($paths);

        $this->logger->info('Starting analysis', [
            'paths' => array_map(static fn(AbsolutePath $p): string => $p->value(), $pathList),
        ]);

        $repository = $this->repositoryFactory->create();
        // Phase 1: Discovery
        $profiler->start('discovery', 'pipeline');
        $config = $this->configurationProvider->getConfiguration();
        $discoveredFiles = $this->analysisFileDiscovery->discover(
            $pathList,
            $config->projectRoot,
            $config->includeGenerated ? GeneratedFilePolicy::Include : GeneratedFilePolicy::Exclude,
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
        $collectionOutput = $this->collectionOrchestrator->collect($files, $repository, $config->projectRoot);
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
        $this->ruleProducerPreparation->inspectFiles($files);

        // Phase 7: Rule execution.
        $phaseStartTime = microtime(true);
        $this->logger->debug('Starting analysis phase');

        $profiler->start('rules', 'pipeline');
        $violations = $this->executeRulesForRun($repository, $graph, $namespaceTree, $collectionResult);
        $profiler->stop('rules');

        $analysisTime = microtime(true) - $phaseStartTime;
        $this->logger->info('Analysis completed', [
            'violations' => \count($violations),
            'duration' => \sprintf('%.2fs', $analysisTime),
        ]);

        // Build result
        $duration = microtime(true) - $startTime;
        $eligiblePaths = array_map(
            static fn(SplFileInfo $file): RelativePath => PathFactory::bestEffortRelative(
                $file->getPathname(),
                $config->projectRoot,
            ),
            $files,
        );
        $coverage = self::buildCoverage($eligiblePaths, $generatedExcludedFiles, $collectionResult);

        $this->logger->info('Analysis complete', [
            'total_duration' => \sprintf('%.2fs', $duration),
            'violations' => \count($violations),
            'files_analyzed' => $collectionResult->filesAnalyzed,
            'files_skipped' => $coverage->skippedFilesCount(),
        ]);

        $profiler->stop('analysis');

        return new AnalysisResult(
            violations: $violations,
            duration: $duration,
            metrics: $repository,
            coverage: $coverage,
            suppressions: $collectionResult->suppressions,
            namespaceTree: $namespaceTree,
            thresholdOverrides: $collectionResult->thresholdOverrides,
        );
    }

    /** @param list<Dependency> $dependencies */
    private function buildDependencyGraph(
        array $dependencies,
        MetricRepositoryInterface $repository,
    ): DependencyGraphInterface {
        return $this->graphBuilder->build($dependencies, self::collectLogicalClassPaths($repository));
    }

    /** @return list<Violation> */
    private function executeRulesForRun(
        MetricRepositoryInterface $repository,
        DependencyGraphInterface $graph,
        NamespaceTree $namespaceTree,
        CollectionPhaseOutput $collectionResult,
    ): array {
        $context = new AnalysisContext(
            metrics: $repository,
            ruleOptions: $this->configurationProvider->getRuleOptions(),
            dependencyGraph: $graph,
            namespaceTree: $namespaceTree,
            thresholdOverrides: $collectionResult->thresholdOverrides,
        );
        $violations = $this->ruleExecutor->execute($context);
        $extraViolations = array_merge(
            self::buildDiagnosticViolations($collectionResult->thresholdDiagnostics),
            $this->buildUnsupportedOverrideViolations($collectionResult->thresholdOverrides),
        );

        return $extraViolations === [] ? $violations : array_merge($violations, $extraViolations);
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
     * Builds warnings for threshold override annotations targeting unsupported rules.
     *
     * Rules like design.god-class have multi-threshold Options that don't implement
     * ThresholdAwareOptionsInterface. Annotations targeting them are silently ignored
     * at runtime — this method emits explicit warnings so users know.
     *
     * @param array<string, list<ThresholdOverride>> $overridesByFile
     *
     * @return list<Violation>
     */
    private function buildUnsupportedOverrideViolations(array $overridesByFile): array
    {
        if ($overridesByFile === []) {
            return [];
        }

        $supportedRules = $this->collectThresholdSupportedRuleNames();

        $violations = [];

        foreach ($overridesByFile as $file => $overrides) {
            $relFile = RelativePath::fromString($file);

            foreach ($overrides as $override) {
                if ($override->rulePattern === '*') {
                    continue;
                }

                if (!$this->overrideMatchesSupportedRule($override, $supportedRules)) {
                    $violations[] = new Violation(
                        location: new Location($relFile, $override->line, precise: true),
                        subject: $override->subject,
                        symbolPath: $override->subject->toSymbolPath(),
                        ruleName: 'annotation.unsupported-threshold',
                        violationCode: 'annotation.unsupported-threshold',
                        message: \sprintf(
                            "Rule '%s' does not support @qmx-threshold overrides; annotation ignored",
                            $override->rulePattern,
                        ),
                        severity: Severity::Warning,
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * Collects names of active rules that support threshold overrides.
     *
     * @return list<string>
     */
    private function collectThresholdSupportedRuleNames(): array
    {
        return array_values(array_map(
            static fn($rule): string => $rule->name,
            array_filter(
                $this->ruleExecutor->allRules(),
                fn($rule): bool => $this->ruleSupportsThresholdOverrides($rule->optionsClass),
            ),
        ));
    }

    /**
     * @param class-string $optionsClass
     */
    private function ruleSupportsThresholdOverrides(string $optionsClass): bool
    {
        if (is_subclass_of($optionsClass, ThresholdAwareOptionsInterface::class)) {
            return true;
        }

        if (!is_subclass_of($optionsClass, HierarchicalRuleOptionsInterface::class)) {
            return false;
        }

        // Hierarchical rules delegate to level-specific options that may support overrides
        $options = $optionsClass::fromArray([]);
        \assert($options instanceof HierarchicalRuleOptionsInterface);

        foreach ($options->getSupportedLevels() as $level) {
            if ($options->forLevel($level) instanceof ThresholdAwareOptionsInterface) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $supportedRules
     */
    private function overrideMatchesSupportedRule(ThresholdOverride $override, array $supportedRules): bool
    {
        return array_any(
            $supportedRules,
            static fn(string $ruleName): bool => RuleMatcher::matches($override->rulePattern, $ruleName),
        );
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
        foreach ($repository->all(SymbolType::Class_) as $classSymbol) {
            $paths[] = $classSymbol->symbolPath;
        }

        return $paths;
    }

    /**
     * Converts threshold annotation diagnostics to warning-level violations.
     *
     * The diagnostic's stable `code` (set by per-rule validators —
     * `warning_exceeds_error`, `error_not_supported`, etc.) becomes a
     * specific `annotation.invalid-threshold.<code>` violationCode so
     * machine-readable formats (SARIF, JSON, GitLab Code Quality) can
     * cross-reference the rejection class. The diagnostic's optional
     * `hint` flows into `recommendation` so users see actionable
     * follow-up.
     *
     * @param array<string, list<ThresholdDiagnostic>> $diagnosticsByFile
     *
     * @return list<Violation>
     */
    private static function buildDiagnosticViolations(array $diagnosticsByFile): array
    {
        $violations = [];

        foreach ($diagnosticsByFile as $file => $diagnostics) {
            $relFile = RelativePath::fromString($file);

            foreach ($diagnostics as $diagnostic) {
                $violationCode = $diagnostic->code !== null
                    ? 'annotation.invalid-threshold.' . $diagnostic->code
                    : 'annotation.invalid-threshold';

                $violations[] = new Violation(
                    location: new Location($relFile, $diagnostic->line, precise: true),
                    subject: $diagnostic->subject,
                    symbolPath: $diagnostic->subject->toSymbolPath(),
                    ruleName: 'annotation.invalid-threshold',
                    violationCode: $violationCode,
                    message: $diagnostic->message,
                    severity: Severity::Warning,
                    recommendation: $diagnostic->hint,
                );
            }
        }

        return $violations;
    }
}
