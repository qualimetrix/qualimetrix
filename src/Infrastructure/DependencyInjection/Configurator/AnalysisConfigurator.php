<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfigurationProviderInterface;
use Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedMetricExtractorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\FileMeasurementCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MeasurementAggregationInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryFactoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\SuppressionExtractor;
use Qualimetrix\Analysis\Policy\Inline\Contract\ThresholdOverrideExtractor;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\Strategy\StrategySelectorInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryFactoryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\GeneratedFileFilterInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Infrastructure\Console\Progress\DelegatingProgressReporter;
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
                new Reference(DelegatingProgressReporter::class),
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
                '$profilerHolder' => new Reference(ProfilerHolder::class),
            ]);

        $this->registerRuleProducerPreparation($container);
        $this->registerAnalysisPipeline($container);
    }

    private function registerRuleProducerPreparation(ContainerBuilder $container): void
    {
        $container->register(self::RULE_PRODUCER_PREPARATION, self::RULE_PRODUCER_PREPARATION_CLASS)
            ->setArguments([
                new Reference(LayerPolicyPreparationInterface::class),
                new Reference(CircularDependencyPreparationInterface::class),
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
                new Reference(TransitionalRuntimeConfigurationProviderInterface::class),
                new Reference(self::RULE_PRODUCER_PREPARATION),
                new Reference(MeasurementAggregationInterface::class),
                new Reference($computedMetricEvaluation),
                new Reference(DependencyGraphBuilderInterface::class),
                new Reference(MetricRepositoryFactoryInterface::class),
                new Reference(DelegatingLogger::class),
                new Reference(ProfilerHolder::class),
            ])
            ->setPublic(true);
        $container->setAlias(AnalysisPipelineInterface::class, self::ANALYSIS_PIPELINE)
            ->setPublic(true);
    }
}
