<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Pipeline;

use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Collection\CollectionResult;
use Qualimetrix\Analysis\Collection\Dependency\DependencyGraph;
use Qualimetrix\Analysis\Collection\Dependency\DependencyGraphBuilder;
use Qualimetrix\Analysis\Collection\FileProcessingFailureKind;
use Qualimetrix\Analysis\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Discovery\GeneratedFileFilter;
use Qualimetrix\Analysis\Repository\DefaultMetricRepositoryFactory;
use Qualimetrix\Analysis\Repository\MetricRepositoryFactoryInterface;
use Qualimetrix\Analysis\RuleExecution\RuleExecutorInterface;
use Qualimetrix\Architecture\Domain\Layer\ClassContextFactory;
use Qualimetrix\Architecture\Domain\Layer\ClassSet;
use Qualimetrix\Architecture\Processing\ArchitectureProcessorInterface;
use Qualimetrix\Architecture\Rules\LayerViolationRule;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Core\Profiler\ProfilerInterface;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Core\Rule\InMemoryRuleChannelRegistry;
use Qualimetrix\Core\Rule\RuleMatcher;
use Qualimetrix\Core\Rule\RuleSelector;
use Qualimetrix\Core\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Core\Suppression\ThresholdDiagnostic;
use Qualimetrix\Core\Suppression\ThresholdOverride;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use SplFileInfo;

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
        private readonly ArchitectureProcessorInterface $architectureProcessor,
        private readonly MetricRepositoryFactoryInterface $repositoryFactory = new DefaultMetricRepositoryFactory(),
        ?DependencyGraphBuilder $graphBuilder = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?ProfilerHolder $profilerHolder = null,
        private readonly RuleSelector $ruleSelector = new RuleSelector(new InMemoryRuleChannelRegistry()),
    ) {
        $this->graphBuilder = $graphBuilder ?? new DependencyGraphBuilder();
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
        $discovery ??= $this->defaultDiscovery;

        // Phase 1: Discovery
        $profiler->start('discovery', 'pipeline');
        $config = $this->configurationProvider->getConfiguration();
        [$files, $generatedExcludedFiles, $discoveredCount] = self::discoverAnalysisFiles(
            $discovery,
            $pathList,
            $config->projectRoot,
            $config->includeGenerated,
        );

        $profiler->stop('discovery');

        $this->logger->info('Discovered files', ['count' => $discoveredCount]);

        if ($generatedExcludedFiles !== []) {
            $this->logger->info('Skipped @generated files', ['count' => \count($generatedExcludedFiles)]);
        }

        // Phase 2: Collection (metrics + dependencies in single AST traversal)
        $phaseStartTime = microtime(true);
        $this->logger->debug('Starting collection phase', ['files' => \count($files)]);

        $profiler->start('collection', 'pipeline');
        $collectionOutput = $this->collectionOrchestrator->collect($files, $repository, $config->projectRoot);
        $collectionResult = $collectionOutput->result;
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

        // Phase 2.6: Prepare ArchitectureProcessor with this run's graph and
        // class set (ADR 0008). The processor expands template layers (when
        // present), binds the registry's ClassContextFactory to $graph, and
        // exposes the resulting ArchitectureConfiguration through
        // getPreparedConfiguration() for the rule layer.
        //
        // Skipped when `architecture.layer-violation` is disabled — that is the
        // only consumer of getPreparedConfiguration() (its diagnostics
        // architecture.empty-template / .unreachable-layer / .potential-shadow
        // are emitted from the same rule). Template expansion and graph bind
        // are pure waste in that case. Symmetric with the duplication skip in
        // MetricEnricher (CLAUDE.md §3).
        //
        // ADR 0008 §3 fail-fast contract: bind() must have been called before
        // prepare(). Production wiring (RuntimeConfigurator) binds before this
        // pipeline runs. Tests that construct AnalysisPipeline directly must
        // provide an already-bound processor — TestPipelineBuilder handles this.
        $this->prepareArchitectureForRun(
            $graph,
            $repository,
            $config->onlyRules,
            $config->disabledRules,
            $profiler,
        );

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

        $profiler->start('rules', 'pipeline');
        $violations = $this->executeRulesForRun($repository, $graph, $enrichmentResult, $collectionResult);
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
            namespaceTree: $enrichmentResult->namespaceTree,
            thresholdOverrides: $collectionResult->thresholdOverrides,
        );
    }

    /** @param list<\Qualimetrix\Core\Dependency\Dependency> $dependencies */
    private function buildDependencyGraph(
        array $dependencies,
        MetricRepositoryInterface $repository,
    ): DependencyGraph {
        return $this->graphBuilder->build($dependencies, self::collectLogicalClassPaths($repository));
    }

    /**
     * @param list<string> $onlyRules
     * @param list<string> $disabledRules
     */
    private function prepareArchitectureForRun(
        DependencyGraph $graph,
        MetricRepositoryInterface $repository,
        array $onlyRules,
        array $disabledRules,
        ProfilerInterface $profiler,
    ): void {
        if (!$this->ruleSelector->isProducerEnabled(LayerViolationRule::NAME, $onlyRules, $disabledRules)) {
            return;
        }

        $profiler->start('architecture-prepare', 'pipeline');
        $classSet = new ClassSet(self::collectClassPaths($repository), new ClassContextFactory());
        $this->architectureProcessor->prepare($graph, $classSet);
        $profiler->stop('architecture-prepare');
    }

    /** @return list<Violation> */
    private function executeRulesForRun(
        MetricRepositoryInterface $repository,
        DependencyGraph $graph,
        EnrichmentResult $enrichmentResult,
        CollectionResult $collectionResult,
    ): array {
        $context = new AnalysisContext(
            metrics: $repository,
            ruleOptions: $this->configurationProvider->getRuleOptions(),
            dependencyGraph: $graph,
            cycles: $enrichmentResult->cycles,
            namespaceTree: $enrichmentResult->namespaceTree,
            thresholdOverrides: $collectionResult->thresholdOverrides,
        );
        $violations = $this->ruleExecutor->execute($context);
        $extraViolations = array_merge(
            self::buildDiagnosticViolations($collectionResult->thresholdDiagnostics),
            $this->buildUnsupportedOverrideViolations($collectionResult->thresholdOverrides),
        );

        return $extraViolations === [] ? $violations : array_merge($violations, $extraViolations);
    }

    /**
     * Discovers unique paths and assigns generated files their terminal state.
     *
     * @param list<AbsolutePath> $paths
     *
     * @return array{list<SplFileInfo>, list<RelativePath>, int}
     */
    private static function discoverAnalysisFiles(
        FileDiscoveryInterface $discovery,
        array $paths,
        AbsolutePath $projectRoot,
        bool $includeGenerated,
    ): array {
        // preserve_keys=false: discover() yields AbsolutePath as the key (object — invalid array key).
        $discoveredFiles = iterator_to_array($discovery->discover($paths), false);

        // Overlapping requested roots may yield the same path more than once.
        $filesByPath = [];
        foreach ($discoveredFiles as $file) {
            $relativePath = PathFactory::bestEffortRelative($file->getPathname(), $projectRoot);
            $filesByPath[$relativePath->value()] = $file;
        }

        if ($includeGenerated) {
            return [array_values($filesByPath), [], \count($filesByPath)];
        }

        $files = [];
        $generatedExcludedFiles = [];
        $generatedFilter = new GeneratedFileFilter();
        foreach ($filesByPath as $relativePath => $file) {
            if ($generatedFilter->isGenerated($file)) {
                $generatedExcludedFiles[] = RelativePath::fromString($relativePath);
            } else {
                $files[] = $file;
            }
        }

        return [$files, $generatedExcludedFiles, \count($filesByPath)];
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
        CollectionResult $collectionResult,
    ): AnalysisCoverage {
        $failures = array_map(
            static function ($failure): AnalysisFailure {
                $processingFailure = $failure->processingFailure();

                return new AnalysisFailure(
                    $failure->filePath,
                    match ($processingFailure->kind) {
                        FileProcessingFailureKind::Parse => AnalysisFailureKind::Parse,
                        FileProcessingFailureKind::Processing => AnalysisFailureKind::Processing,
                    },
                    $processingFailure->message,
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
            static fn($rule): string => $rule->getName(),
            array_filter(
                $this->ruleExecutor->getAllRules(),
                fn($rule): bool => $this->ruleSupportsThresholdOverrides($rule::getOptionsClass()),
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
