<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Pipeline;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Architecture\LayerExpansionStage;
use Qualimetrix\Analysis\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Collection\Dependency\DependencyGraphBuilder;
use Qualimetrix\Analysis\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Discovery\GeneratedFileFilter;
use Qualimetrix\Analysis\Repository\DefaultMetricRepositoryFactory;
use Qualimetrix\Analysis\Repository\MetricRepositoryFactoryInterface;
use Qualimetrix\Analysis\RuleExecution\RuleExecutorInterface;
use Qualimetrix\Architecture\Domain\ArchitectureConfigurationHolder;
use Qualimetrix\Architecture\Domain\Layer\ClassContextFactory;
use Qualimetrix\Architecture\Domain\Layer\ClassSet;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Dependency\DependencyGraphInterface;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Core\Rule\RuleMatcher;
use Qualimetrix\Core\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Core\Suppression\ThresholdDiagnostic;
use Qualimetrix\Core\Suppression\ThresholdOverride;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;

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
        private readonly ArchitectureConfigurationHolder $architectureHolder = new ArchitectureConfigurationHolder(),
        private readonly LayerExpansionStage $layerExpansionStage = new LayerExpansionStage(),
    ) {
        $this->graphBuilder = $graphBuilder ?? new DependencyGraphBuilder();
    }

    public function analyze(string|array $paths, ?FileDiscoveryInterface $discovery = null): AnalysisResult
    {
        $startTime = microtime(true);
        $profiler = $this->profilerHolder?->get(); // @phpstan-ignore staticMethod.dynamicCall

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

        // Phase 2.6: Architecture layer template expansion (Phase 2 direction 2).
        // Runs only when the user config carries at least one TemplateLayerDefinition.
        // Updates ArchitectureConfigurationHolder so the rule sees the post-expansion
        // registry and empty-template diagnostic list via AnalysisContext.
        $profiler?->start('architecture-expansion', 'pipeline');
        $this->expandArchitectureTemplatesIfNeeded($repository, $graph);
        $profiler?->stop('architecture-expansion');

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
            $enrichmentResult->namespaceTree,
            $collectionResult->thresholdOverrides,
            $this->architectureHolder->get(),
        );
        $violations = $this->ruleExecutor->execute($context);

        // Convert threshold annotation diagnostics to violations
        $diagnosticViolations = self::buildDiagnosticViolations($collectionResult->thresholdDiagnostics);

        // Warn about `@qmx-threshold` annotations targeting rules that don't support overrides
        $unsupportedViolations = $this->buildUnsupportedOverrideViolations(
            $collectionResult->thresholdOverrides,
        );

        $extraViolations = array_merge($diagnosticViolations, $unsupportedViolations);
        if ($extraViolations !== []) {
            $violations = array_merge($violations, $extraViolations);
        }

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
            namespaceTree: $enrichmentResult->namespaceTree,
        );
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
            foreach ($overrides as $override) {
                if ($override->rulePattern === '*') {
                    continue;
                }

                if (!$this->overrideMatchesSupportedRule($override, $supportedRules)) {
                    $violations[] = new Violation(
                        location: new Location($file, $override->line, precise: true),
                        symbolPath: SymbolPath::forFile($file),
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
        $supported = [];

        foreach ($this->ruleExecutor->getAllRules() as $rule) {
            if ($this->ruleSupportsThresholdOverrides($rule::getOptionsClass())) {
                $supported[] = $rule->getName();
            }
        }

        return $supported;
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
        foreach ($supportedRules as $ruleName) {
            if (RuleMatcher::matches($override->rulePattern, $ruleName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Runs the {@see LayerExpansionStage} when the active architecture
     * configuration carries at least one {@see \Qualimetrix\Architecture\Domain\Layer\TemplateLayerDefinition},
     * and writes the post-expansion configuration back into the
     * {@see ArchitectureConfigurationHolder}.
     *
     * No-op for Phase-1-shape configurations (no templates) — the pipeline
     * runs unchanged and the holder keeps its original
     * {@see ArchitectureConfiguration}.
     */
    private function expandArchitectureTemplatesIfNeeded(
        MetricRepositoryInterface $repository,
        DependencyGraphInterface $graph,
    ): void {
        $architecture = $this->architectureHolder->get();
        if (!$architecture->hasTemplates()) {
            return;
        }

        $contextFactory = new ClassContextFactory();
        $contextFactory->bindGraph($graph);

        $classSet = new ClassSet(
            self::collectClassPaths($repository),
            $contextFactory,
        );

        $expansion = $this->layerExpansionStage->expand(
            $architecture->entries(),
            $classSet,
            $architecture->maxExpandedLayers(),
        );

        $this->architectureHolder->set(
            $architecture->withExpansion(
                $expansion->expandedLayers,
                $expansion->emptyTemplateNames,
            ),
        );

        $this->logger->debug('Architecture template expansion completed', [
            'expanded_layers' => \count($expansion->expandedLayers),
            'empty_templates' => \count($expansion->emptyTemplateNames),
        ]);
    }

    /**
     * Collects the {@see SymbolPath} for every class symbol recorded in the
     * metric repository — the input set for template expansion.
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
     * @param array<string, list<ThresholdDiagnostic>> $diagnosticsByFile
     *
     * @return list<Violation>
     */
    private static function buildDiagnosticViolations(array $diagnosticsByFile): array
    {
        $violations = [];

        foreach ($diagnosticsByFile as $file => $diagnostics) {
            foreach ($diagnostics as $diagnostic) {
                $violations[] = new Violation(
                    location: new Location($file, $diagnostic->line, precise: true),
                    symbolPath: SymbolPath::forFile($file),
                    ruleName: 'annotation.invalid-threshold',
                    violationCode: 'annotation.invalid-threshold',
                    message: $diagnostic->message,
                    severity: Severity::Warning,
                );
            }
        }

        return $violations;
    }
}
