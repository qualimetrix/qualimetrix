<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Aggregator\GlobalCollectorRunner;
use Qualimetrix\Analysis\Collection\CollectionOrchestrator;
use Qualimetrix\Analysis\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Collection\Dependency\DependencyGraphBuilder;
use Qualimetrix\Analysis\Collection\FileProcessor;
use Qualimetrix\Analysis\Collection\FileProcessorInterface;
use Qualimetrix\Analysis\Collection\Metric\CompositeCollector;
use Qualimetrix\Analysis\Collection\Metric\DerivedMetricExtractor;
use Qualimetrix\Analysis\Collection\Strategy\StrategySelectorInterface;
use Qualimetrix\Analysis\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Discovery\FinderFileDiscovery;
use Qualimetrix\Analysis\Duplication\DuplicationDetector;
use Qualimetrix\Analysis\Duplication\DuplicationDetectorInterface;
use Qualimetrix\Analysis\Namespace_\ProjectNamespaceResolver;
use Qualimetrix\Analysis\Pipeline\AnalysisPipeline;
use Qualimetrix\Analysis\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Pipeline\MetricEnricher;
use Qualimetrix\Analysis\Repository\DefaultMetricRepositoryFactory;
use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Repository\MetricRepositoryFactoryInterface;
use Qualimetrix\Analysis\RuleExecution\RuleExecutor;
use Qualimetrix\Analysis\RuleExecution\RuleExecutorInterface;
use Qualimetrix\Architecture\Processing\ArchitectureProcessorInterface;
use Qualimetrix\Baseline\Suppression\ThresholdOverrideExtractor;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Configuration\RuleOptionsRegistry;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Namespace_\ProjectNamespaceResolverInterface;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Infrastructure\Console\Progress\DelegatingProgressReporter;
use Qualimetrix\Infrastructure\Logging\DelegatingLogger;
use Qualimetrix\Infrastructure\Parallel\Strategy\StrategySelector;
use Qualimetrix\Metrics\ComputedMetric\ComputedMetricEvaluator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Configures analysis pipeline and related services.
 */
final class AnalysisConfigurator implements ContainerConfiguratorInterface
{
    public function configure(ContainerBuilder $container): void
    {
        $container->register(FinderFileDiscovery::class);
        $container->setAlias(FileDiscoveryInterface::class, FinderFileDiscovery::class);

        // ProjectNamespaceResolver — used by DistanceRule for namespace filtering
        $container->register(ProjectNamespaceResolver::class)
            ->setPublic(true);
        $container->setAlias(ProjectNamespaceResolverInterface::class, ProjectNamespaceResolver::class)
            ->setPublic(true);

        $container->register(InMemoryMetricRepository::class);
        $container->setAlias(MetricRepositoryInterface::class, InMemoryMetricRepository::class);

        $container->register(DefaultMetricRepositoryFactory::class);
        $container->setAlias(MetricRepositoryFactoryInterface::class, DefaultMetricRepositoryFactory::class);

        // ThresholdOverrideExtractor - per-rule @qmx-threshold validator map injected
        // by ThresholdValidatorMapCompilerPass after RuleRegistryCompilerPass runs
        $container->register(ThresholdOverrideExtractor::class)
            ->setArguments(['$validators' => []]);

        // FileProcessor - processes single files
        // Named arguments — the 3rd positional is SuppressionExtractor (uses default new()),
        // ThresholdOverrideExtractor is the 4th constructor parameter
        $container->register(FileProcessor::class)
            ->setArguments([
                '$parser' => new Reference(FileParserInterface::class),
                '$collector' => new Reference(CompositeCollector::class),
                '$thresholdOverrideExtractor' => new Reference(ThresholdOverrideExtractor::class),
            ]);
        $container->setAlias(FileProcessorInterface::class, FileProcessor::class);

        // StrategySelectorInterface - for lazy strategy selection
        $container->setAlias(StrategySelectorInterface::class, StrategySelector::class);

        // DerivedMetricExtractor - extracts derived method-level metrics from file bags
        $container->register(DerivedMetricExtractor::class)
            ->setArguments([
                new Reference(CompositeCollector::class),
            ]);

        // CollectionOrchestrator - coordinates collection phase
        // Uses StrategySelectorInterface for lazy strategy selection (configuration may not be available at DI time)
        $container->register(CollectionOrchestrator::class)
            ->setArguments([
                new Reference(FileProcessorInterface::class),
                new Reference(StrategySelectorInterface::class),
                new Reference(DerivedMetricExtractor::class),
                new Reference(DelegatingProgressReporter::class),
                new Reference(DelegatingLogger::class),
            ]);
        $container->setAlias(CollectionOrchestratorInterface::class, CollectionOrchestrator::class);

        // GlobalCollectorRunner - runs global collectors
        // Global collectors will be injected by GlobalCollectorCompilerPass
        $container->register(GlobalCollectorRunner::class)
            ->setArguments([
                '$collectors' => [], // Will be set by GlobalCollectorCompilerPass
            ]);

        // RuleExecutor will have rules injected by compiler pass
        $container->register(RuleExecutor::class)
            ->setArguments([
                '$rules' => [], // Will be set by RuleCompilerPass
                '$configurationProvider' => new Reference(ConfigurationProviderInterface::class),
                '$ruleOptionsRegistry' => new Reference(RuleOptionsRegistry::class),
            ]);
        $container->setAlias(RuleExecutorInterface::class, RuleExecutor::class);

        // DependencyGraphBuilder for dependency analysis
        $container->register(DependencyGraphBuilder::class);

        // DuplicationDetector for copy-paste detection
        $container->register(DuplicationDetector::class)
            ->setArguments([
                new Reference(ConfigurationProviderInterface::class),
            ]);
        $container->setAlias(DuplicationDetectorInterface::class, DuplicationDetector::class);

        // MetricEnricher - handles aggregation, global collectors, computed metrics, cycle/duplication detection
        $container->register(MetricEnricher::class)
            ->setArguments([
                new Reference(CompositeCollector::class),
                new Reference(GlobalCollectorRunner::class),
                new Reference(ConfigurationProviderInterface::class),
                new Reference(DelegatingLogger::class),
                new Reference(ProfilerHolder::class),
                new Reference(DuplicationDetector::class),
                new Reference(ComputedMetricEvaluator::class),
            ]);

        // AnalysisPipeline - main orchestrator
        // ArchitectureProcessor (via interface alias registered by
        // ArchitectureConfigurator) is the per-run lifecycle coordinator for
        // architecture rules — see ADR 0008.
        $container->register(AnalysisPipeline::class)
            ->setArguments([
                new Reference(FileDiscoveryInterface::class),
                new Reference(CollectionOrchestratorInterface::class),
                new Reference(RuleExecutorInterface::class),
                new Reference(ConfigurationProviderInterface::class),
                new Reference(MetricEnricher::class),
                new Reference(ArchitectureProcessorInterface::class),
                new Reference(MetricRepositoryFactoryInterface::class),
                new Reference(DependencyGraphBuilder::class),
                new Reference(DelegatingLogger::class),
                new Reference(ProfilerHolder::class),
            ])
            ->setPublic(true);
        $container->setAlias(AnalysisPipelineInterface::class, AnalysisPipeline::class)
            ->setPublic(true);
    }
}
