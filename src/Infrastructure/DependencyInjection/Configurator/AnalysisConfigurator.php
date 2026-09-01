<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedMetricExtractorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\FileMeasurementCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MeasurementAggregationInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryFactoryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\ThresholdDirectiveAuditInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\SuppressionExtractor;
use Qualimetrix\Analysis\Policy\Inline\Contract\ThresholdOverrideExtractor;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\Strategy\StrategySelectorInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryFactoryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\GeneratedFileFilterInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DirectiveAuditInterface;
use Qualimetrix\Analysis\Run\Contract\Progress\ProgressReporterInterface;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleOptionsCompilerPass;
use Qualimetrix\Infrastructure\Logging\DelegatingLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Configures analysis pipeline and related services.
 */
final class AnalysisConfigurator implements ContainerConfiguratorInterface
{
    private const string COLLECTION_ORCHESTRATOR = 'qmx.run.collection_orchestrator';
    private const string COLLECTION_ORCHESTRATOR_CLASS = 'Qualimetrix\\Analysis\\Run\\Collection\\CollectionOrchestrator';
    private const string FILE_SET_INSPECTION_COMPOSITE = 'qmx.analysis.run.file_set_inspection_composite';
    private const string FILE_SET_INSPECTION_COMPOSITE_CLASS = 'Qualimetrix\\Analysis\\Run\\FileSetInspection\\FileSetInspectionComposite';
    private const string ANALYSIS_PIPELINE = 'qmx.run.analysis_pipeline';
    private const string ANALYSIS_PIPELINE_CLASS = 'Qualimetrix\\Analysis\\Run\\Pipeline\\AnalysisPipeline';
    private const string RULE_SELECTOR_PRODUCER_GATE = 'qmx.analysis.run.rule_selector_producer_gate';
    private const string RULE_SELECTOR_PRODUCER_GATE_CLASS = 'Qualimetrix\\Analysis\\Run\\FileSetInspection\\RuleSelectorProducerGate';
    private const string RULE_PRODUCER_PREPARATION = 'qmx.analysis.run.rule_producer_preparation';
    private const string RULE_PRODUCER_PREPARATION_CLASS = 'Qualimetrix\\Analysis\\Run\\RuleProducerPreparation';
    private const string FILE_DISCOVERY = 'qmx.run.file_discovery';
    private const string FILE_DISCOVERY_CLASS = 'Qualimetrix\\Analysis\\Run\\Discovery\\FinderFileDiscovery';
    private const string ANALYSIS_FILE_DISCOVERY = 'qmx.analysis.run.file_discovery';
    private const string ANALYSIS_FILE_DISCOVERY_CLASS = 'Qualimetrix\\Analysis\\Run\\Discovery\\AnalysisFileDiscovery';
    private const string FILE_PROCESSOR = 'qmx.run.file_processor';
    private const string FILE_PROCESSOR_CLASS = 'Qualimetrix\\Analysis\\Run\\Collection\\FileProcessor';
    private const string SOURCE_CONTROL_EXTRACTOR_CLASS = 'Qualimetrix\\Analysis\\Policy\\Inline\\Extraction\\SourceControlExtractor';
    private const string INLINE_DIRECTIVE_POLICY_CLASS = 'Qualimetrix\\Analysis\\Policy\\Inline\\Directive\\InlineDirectivePolicy';
    private const string INLINE_DIRECTIVE_USAGE_CLASS = 'Qualimetrix\\Analysis\\Policy\\Inline\\Directive\\DirectiveUsage';
    private const string INLINE_THRESHOLD_AUDIT_CLASS = 'Qualimetrix\\Analysis\\Policy\\Inline\\Directive\\ThresholdDirectiveAudit';
    private const string INLINE_DIRECTIVE_RULE_CLASS = 'Qualimetrix\\Analysis\\Policy\\Inline\\Directive\\UnusedDirectiveRule';
    private const string INLINE_DIRECTIVE_VALIDATOR_CLASS = 'Qualimetrix\\Analysis\\Policy\\Inline\\Directive\\InlineDirectiveValidator';
    private const string FILE_DISCOVERY_FACTORY = 'qmx.run.file_discovery_factory';
    private const string FILE_DISCOVERY_FACTORY_CLASS = 'Qualimetrix\\Analysis\\Run\\Discovery\\FileDiscoveryFactory';
    private const string GENERATED_FILE_FILTER = 'qmx.run.generated_file_filter';
    private const string GENERATED_FILE_FILTER_CLASS = 'Qualimetrix\\Analysis\\Run\\Discovery\\GeneratedFileFilter';

    public function configure(ContainerBuilder $container): void
    {
        $container->register(self::FILE_DISCOVERY, self::FILE_DISCOVERY_CLASS);
        $container->setAlias(FileDiscoveryInterface::class, self::FILE_DISCOVERY);
        $container->register(self::FILE_DISCOVERY_FACTORY, self::FILE_DISCOVERY_FACTORY_CLASS);
        $container->setAlias(FileDiscoveryFactoryInterface::class, self::FILE_DISCOVERY_FACTORY);
        $container->register(self::GENERATED_FILE_FILTER, self::GENERATED_FILE_FILTER_CLASS);
        $container->setAlias(GeneratedFileFilterInterface::class, self::GENERATED_FILE_FILTER);
        $container->register(self::ANALYSIS_FILE_DISCOVERY, self::ANALYSIS_FILE_DISCOVERY_CLASS)
            ->setArguments([
                new Reference(FileDiscoveryInterface::class),
                new Reference(GeneratedFileFilterInterface::class),
            ]);

        // ThresholdOverrideExtractor - per-rule @qmx-threshold validator map injected
        // by ThresholdValidatorMapCompilerPass after RuleRegistryCompilerPass runs
        $container->register(ThresholdOverrideExtractor::class)
            ->setArguments(['$validators' => []]);
        $container->register(SuppressionExtractor::class);
        $privateExtractorId = self::SOURCE_CONTROL_EXTRACTOR_CLASS;
        $container->register($privateExtractorId, $privateExtractorId)
            ->setArguments([
                new Reference(SuppressionExtractor::class),
                new Reference(ThresholdOverrideExtractor::class),
            ]);

        // FileProcessor - processes single files. projectRoot is set at runtime
        // by CollectionOrchestrator (sequential side) and WorkerBootstrap
        // (parallel side) so the path-VO boundary stays at the file-result edge
        // without a cross-namespace ConfigurationProvider dependency.
        $container->register(self::FILE_PROCESSOR, self::FILE_PROCESSOR_CLASS)
            ->setArguments([
                '$parser' => new Reference(FileParserInterface::class),
                '$collector' => new Reference(FileMeasurementCollectorInterface::class),
                '$sourceControlExtractor' => new Reference($privateExtractorId),
            ]);
        $container->setAlias(FileProcessorInterface::class, self::FILE_PROCESSOR);

        // CollectionOrchestrator - coordinates collection phase
        // Uses StrategySelectorInterface for lazy strategy selection (configuration may not be available at DI time)
        $container->register(self::COLLECTION_ORCHESTRATOR, self::COLLECTION_ORCHESTRATOR_CLASS)
            ->setArguments([
                new Reference(FileProcessorInterface::class),
                new Reference(StrategySelectorInterface::class),
                new Reference(DerivedMetricExtractorInterface::class),
                new Reference(ProgressReporterInterface::class),
                new Reference(ProfilerInterface::class),
                new Reference(DelegatingLogger::class),
            ]);
        $container->setAlias(CollectionOrchestratorInterface::class, self::COLLECTION_ORCHESTRATOR);

        // DependencyModel publishes the builder only through its contract.
        $container->register(
            'dependency_model.graph_builder',
            'Qualimetrix\\Analysis\\Evidence\\DependencyModel\\DependencyGraphBuilder',
        );
        $container->setAlias(DependencyGraphBuilderInterface::class, 'dependency_model.graph_builder')
            ->setPublic(true);

        $container->register(self::RULE_SELECTOR_PRODUCER_GATE, self::RULE_SELECTOR_PRODUCER_GATE_CLASS)
            ->setArgument('$ruleSelector', new Reference(RuleSelector::class));
        $container->register(self::FILE_SET_INSPECTION_COMPOSITE, self::FILE_SET_INSPECTION_COMPOSITE_CLASS)
            ->setArguments([
                '$participants' => [],
                '$producerGate' => new Reference(self::RULE_SELECTOR_PRODUCER_GATE),
                '$profiler' => new Reference(ProfilerInterface::class),
            ]);

        $this->registerInlineDirectivePolicy($container);
        $this->registerRuleProducerPreparation($container);
        $this->registerAnalysisPipeline($container);
    }

    /**
     * The inline-directive capability's own run state, plus the rule that
     * reports on it. The rule is registered here rather than by a scan
     * because Inline owns exactly one rule and an open-ended pattern would
     * silently enrol a future sibling.
     */
    private function registerInlineDirectivePolicy(ContainerBuilder $container): void
    {
        $container->register(self::INLINE_DIRECTIVE_USAGE_CLASS, self::INLINE_DIRECTIVE_USAGE_CLASS)
            ->setArguments([
                new Reference(ChannelIdentityInterface::class),
                new Reference(RuleSelector::class),
                new Reference(RuleConfigurationInterface::class),
                new Reference(ChannelDeclarationRegistryInterface::class),
            ]);

        // The threshold half is a service of its own rather than a method on
        // the policy: it needs no run state at all — the run hands it the
        // context it already prepared — while the policy is exactly that state.
        $container->register(self::INLINE_THRESHOLD_AUDIT_CLASS, self::INLINE_THRESHOLD_AUDIT_CLASS)
            ->setArguments([
                new Reference(ChannelIdentityInterface::class),
                new Reference(RuleSelector::class),
                new Reference(RuleConfigurationInterface::class),
            ]);
        $container->setAlias(ThresholdDirectiveAuditInterface::class, self::INLINE_THRESHOLD_AUDIT_CLASS);

        // The usage accounting is injected rather than built by the policy:
        // the policy is the run's directive store, and the collaborators the
        // accounting needs are not the store's.
        $container->register(self::INLINE_DIRECTIVE_POLICY_CLASS, self::INLINE_DIRECTIVE_POLICY_CLASS)
            ->setArguments([new Reference(self::INLINE_DIRECTIVE_USAGE_CLASS)])
            ->setPublic(true);
        $container->setAlias(InlineDirectivePolicyInterface::class, self::INLINE_DIRECTIVE_POLICY_CLASS)
            ->setPublic(true);

        // Registered before the rule, and that order is load-bearing: channels
        // enter the universe in the order their producers are registered, and
        // this family's published order has the three directive diagnostics
        // ahead of `annotation.unused-directive`. See
        // ChannelDeclarationCompilerPass.
        //
        // The validator answers to the rule's own Options service — the one
        // `--rule-opt=annotation.directive:enabled=false` configures — rather
        // than to a copy of it. The id is derived from the rule the same way
        // RuleOptionsCompilerPass derives it when it registers that service
        // later in the build; a reference to it resolves at the end of
        // compilation.
        $container->register(self::INLINE_DIRECTIVE_VALIDATOR_CLASS, self::INLINE_DIRECTIVE_VALIDATOR_CLASS)
            ->setArguments([
                new Reference(RuleOptionsCompilerPass::optionsServiceIdForRule(self::INLINE_DIRECTIVE_RULE_CLASS)),
                new Reference(self::INLINE_DIRECTIVE_POLICY_CLASS),
                new Reference(ChannelIdentityInterface::class),
            ])
            ->setAutoconfigured(true)
            ->setAutowired(false)
            ->setLazy(true);

        $container->register(self::INLINE_DIRECTIVE_RULE_CLASS, self::INLINE_DIRECTIVE_RULE_CLASS)
            ->setAutoconfigured(true)
            ->setAutowired(false)
            ->setLazy(true);
    }

    private function registerRuleProducerPreparation(ContainerBuilder $container): void
    {
        $container->register(self::RULE_PRODUCER_PREPARATION, self::RULE_PRODUCER_PREPARATION_CLASS)
            ->setArguments([
                new Reference(LayerPolicyPreparationInterface::class),
                new Reference(CircularDependencyPreparationInterface::class),
                new Reference(InlineDirectivePolicyInterface::class),
                new Reference(ThresholdDirectiveAuditInterface::class),
                new Reference(self::FILE_SET_INSPECTION_COMPOSITE),
                new Reference(RuleSelector::class),
                new Reference(RuleConfigurationInterface::class),
            ]);
    }

    private function registerAnalysisPipeline(ContainerBuilder $container): void
    {
        $computedMetricEvaluation = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Evaluation\\ComputedMetricEvaluator';

        // AnalysisPipeline owns the complete phase order while every capability
        // retains its own state behind a narrow public contract.
        $container->register(self::ANALYSIS_PIPELINE, self::ANALYSIS_PIPELINE_CLASS)
            ->setArguments([
                new Reference(self::ANALYSIS_FILE_DISCOVERY),
                new Reference(CollectionOrchestratorInterface::class),
                new Reference(RuleExecutionInterface::class),
                new Reference(self::RULE_PRODUCER_PREPARATION),
                new Reference(MeasurementAggregationInterface::class),
                new Reference($computedMetricEvaluation),
                new Reference(DependencyGraphBuilderInterface::class),
                new Reference(MetricRepositoryFactoryInterface::class),
                new Reference(ProfilerInterface::class),
                new Reference(DelegatingLogger::class),
            ])
            ->setPublic(true);
        $container->setAlias(AnalysisPipelineInterface::class, self::ANALYSIS_PIPELINE)
            ->setPublic(true);

        // The same instance under its second contract. Two aliases and not one
        // wider interface: analysing and auditing directives are two questions,
        // and every consumer of the first would otherwise carry the second.
        $container->setAlias(DirectiveAuditInterface::class, self::ANALYSIS_PIPELINE)
            ->setPublic(true);
    }
}
