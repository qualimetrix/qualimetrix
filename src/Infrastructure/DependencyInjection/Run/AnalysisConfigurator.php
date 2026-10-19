<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Run;

use Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedMetricExtractorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\FileMeasurementCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MeasurementAggregationInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryFactoryInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RulePublicationInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\ThresholdDirectiveAuditInterface;
use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestReaderInterface;
use Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\Strategy\StrategySelectorInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectFilesInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DirectiveAuditInterface;
use Qualimetrix\Analysis\Run\Contract\Progress\ProgressReporterInterface;
use Qualimetrix\Analysis\Run\Measurement\RunMeasurement;
use Qualimetrix\Analysis\Run\Preparation\RunPreparation;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ContainerConfiguratorInterface;
use Qualimetrix\Infrastructure\DependencyInjection\RuleCatalog\RuleOptionsCompilerPass;
use Qualimetrix\Infrastructure\Logging\DelegatingLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/** @qmx-threshold coupling.cbo 32 -- Collection and pipeline composition name each phase port explicitly; the finite bound keeps additional orchestration dependencies visible. */
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
    private const string INLINE_DIRECTIVE_RUN = 'qmx.analysis.run.inline_directive_run';
    private const string INLINE_DIRECTIVE_RUN_CLASS = 'Qualimetrix\\Analysis\\Run\\InlineDirectiveRun';
    private const string FILE_PROCESSOR = 'qmx.run.file_processor';
    private const string FILE_PROCESSOR_CLASS = 'Qualimetrix\\Analysis\\Run\\Collection\\FileProcessor';
    private const string SOURCE_CONTROL_EXTRACTOR_CLASS = 'Qualimetrix\\Analysis\\Policy\\Inline\\Extraction\\SourceControlExtractor';
    private const string UNMATCHED_EXCLUDE_AUDIT_CLASS = 'Qualimetrix\\Analysis\\Run\\ExcludeBinding\\UnmatchedExcludeAudit';
    private const string UNMATCHED_EXCLUDE_RULE_CLASS = 'Qualimetrix\\Analysis\\Run\\ExcludeBinding\\UnmatchedExcludeRule';

    public function configure(ContainerBuilder $container): void
    {
        $container->register(self::FILE_PROCESSOR, self::FILE_PROCESSOR_CLASS)
                    ->setArguments([
                        '$parser' => new Reference(FileParserInterface::class),
                        '$collector' => new Reference(FileMeasurementCollectorInterface::class),
                        '$sourceControlExtractor' => new Reference(self::SOURCE_CONTROL_EXTRACTOR_CLASS),
                    ]);
        $container->setAlias(FileProcessorInterface::class, self::FILE_PROCESSOR);
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
        $container->register(self::RULE_SELECTOR_PRODUCER_GATE, self::RULE_SELECTOR_PRODUCER_GATE_CLASS)
                    ->setArgument('$ruleConfiguration', new Reference(RuleConfigurationInterface::class));
        $container->register(self::FILE_SET_INSPECTION_COMPOSITE, self::FILE_SET_INSPECTION_COMPOSITE_CLASS)
                    ->setArguments([
                        '$participants' => [],
                        '$producerGate' => new Reference(self::RULE_SELECTOR_PRODUCER_GATE),
                        '$profiler' => new Reference(ProfilerInterface::class),
                    ]);
        $this->registerUnmatchedExcludeProducer($container);
        $this->registerInlineDirectiveRun($container);
        $this->registerRuleProducerPreparation($container);
        $this->registerAnalysisPipeline($container);
    }

    /**
         * Run's own producer: the rule that gives the channel an identity, and the
         * audit that measures and builds what it reports.
         *
         * The rule is registered by name rather than found by a scan, for the
         * reason every capability configurator registers its own roots by name —
         * a pattern over `Discovery/` would silently enrol the next class added
         * there.
         *
         * The audit answers to the rule's **own** Options service, derived the way
         * {@see RuleOptionsCompilerPass} derives it when it registers that service
         * later in the build, so one setting is read in one place.
         */
    private function registerUnmatchedExcludeProducer(ContainerBuilder $container): void
    {
        // Lazy, and that is the whole reason the audit may hold its rule's
        // Options service: constructed eagerly it would capture the options as
        // they stood before the console applied `rules.<name>.enabled` or
        // `--rule-opt`. Its first call is inside discovery, by which time the
        // runtime configuration is settled.
        $container->register(self::UNMATCHED_EXCLUDE_AUDIT_CLASS, self::UNMATCHED_EXCLUDE_AUDIT_CLASS)
            ->setArguments([
                new Reference(RuleOptionsCompilerPass::optionsServiceIdForRule(self::UNMATCHED_EXCLUDE_RULE_CLASS)),
            ])
            ->setLazy(true);

        $container->register(self::UNMATCHED_EXCLUDE_RULE_CLASS, self::UNMATCHED_EXCLUDE_RULE_CLASS)
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
                new Reference(self::FILE_SET_INSPECTION_COMPOSITE),
                new Reference(self::RULE_SELECTOR_PRODUCER_GATE),
            ]);
    }

    private function registerInlineDirectiveRun(ContainerBuilder $container): void
    {
        $container->register(self::INLINE_DIRECTIVE_RUN, self::INLINE_DIRECTIVE_RUN_CLASS)
            ->setArguments([
                new Reference(InlineDirectivePolicyInterface::class),
                new Reference(ThresholdDirectiveAuditInterface::class),
            ]);
    }

    private function registerAnalysisPipeline(ContainerBuilder $container): void
    {
        // One instance answers the scope question: the run configuration
        // resolver stamps its answer onto every RunConfiguration, and the
        // console derives its incomplete-scope warning from the same answer.
        $container->register(ProjectScopeCoverage::class)
            ->setArgument('$composerReader', new Reference(ComposerManifestReaderInterface::class))
            ->setPublic(true);

        $computedMetricEvaluation = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Evaluation\\ComputedMetricEvaluatorInterface';
        $container->register(RunMeasurement::class)
            ->setArguments([
                new Reference(MetricRepositoryFactoryInterface::class),
                new Reference(MeasurementAggregationInterface::class),
                new Reference($computedMetricEvaluation),
            ]);

        // AnalysisPipeline owns the complete phase order while every capability
        // retains its own state behind a narrow public contract.
        $container->register(RunPreparation::class)
            ->setArguments([
                new Reference(ProjectFilesInterface::class),
                new Reference(self::UNMATCHED_EXCLUDE_AUDIT_CLASS),
                new Reference(CollectionOrchestratorInterface::class),
                new Reference(RuleExecutionInterface::class),
                new Reference(RulePublicationInterface::class),
                new Reference(self::RULE_PRODUCER_PREPARATION),
                new Reference(self::INLINE_DIRECTIVE_RUN),
                new Reference(RunMeasurement::class),
                new Reference(DependencyGraphBuilderInterface::class),
                new Reference(ProfilerInterface::class),
                new Reference(DelegatingLogger::class),
            ]);
        $container->register(self::ANALYSIS_PIPELINE, self::ANALYSIS_PIPELINE_CLASS)
            ->setArguments([
                new Reference(RunPreparation::class),
                new Reference(RuleExecutionInterface::class),
                new Reference(RulePublicationInterface::class),
                new Reference(self::INLINE_DIRECTIVE_RUN),
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
