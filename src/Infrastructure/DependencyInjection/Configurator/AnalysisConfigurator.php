<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\DependencyInjection\Configurator;

use AiMessDetector\Analysis\Aggregator\GlobalCollectorRunner;
use AiMessDetector\Analysis\Collection\CollectionOrchestrator;
use AiMessDetector\Analysis\Collection\CollectionOrchestratorInterface;
use AiMessDetector\Analysis\Collection\Dependency\DependencyGraphBuilder;
use AiMessDetector\Analysis\Collection\FileProcessor;
use AiMessDetector\Analysis\Collection\FileProcessorInterface;
use AiMessDetector\Analysis\Collection\Metric\CompositeCollector;
use AiMessDetector\Analysis\Collection\Metric\DerivedMetricExtractor;
use AiMessDetector\Analysis\Collection\Strategy\StrategySelectorInterface;
use AiMessDetector\Analysis\Discovery\FileDiscoveryInterface;
use AiMessDetector\Analysis\Discovery\FinderFileDiscovery;
use AiMessDetector\Analysis\Duplication\DuplicationDetector;
use AiMessDetector\Analysis\Namespace_\ProjectNamespaceResolver;
use AiMessDetector\Analysis\Pipeline\AnalysisPipeline;
use AiMessDetector\Analysis\Pipeline\AnalysisPipelineInterface;
use AiMessDetector\Analysis\Repository\DefaultMetricRepositoryFactory;
use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Analysis\Repository\MetricRepositoryFactoryInterface;
use AiMessDetector\Analysis\RuleExecution\RuleExecutor;
use AiMessDetector\Analysis\RuleExecution\RuleExecutorInterface;
use AiMessDetector\Configuration\ConfigurationProviderInterface;
use AiMessDetector\Core\Ast\FileParserInterface;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Namespace_\ProjectNamespaceResolverInterface;
use AiMessDetector\Core\Profiler\ProfilerHolder;
use AiMessDetector\Infrastructure\Console\Progress\DelegatingProgressReporter;
use AiMessDetector\Infrastructure\Logging\DelegatingLogger;
use AiMessDetector\Infrastructure\Parallel\Strategy\StrategySelector;
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

        // FileProcessor - processes single files
        $container->register(FileProcessor::class)
            ->setArguments([
                new Reference(FileParserInterface::class),
                new Reference(CompositeCollector::class),
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
            ]);
        $container->setAlias(RuleExecutorInterface::class, RuleExecutor::class);

        // DependencyGraphBuilder for dependency analysis
        $container->register(DependencyGraphBuilder::class);

        // DuplicationDetector for copy-paste detection
        $container->register(DuplicationDetector::class)
            ->setArguments([
                new Reference(ConfigurationProviderInterface::class),
            ]);

        // AnalysisPipeline - main orchestrator
        $container->register(AnalysisPipeline::class)
            ->setArguments([
                new Reference(FileDiscoveryInterface::class),
                new Reference(CollectionOrchestratorInterface::class),
                new Reference(CompositeCollector::class),
                new Reference(RuleExecutorInterface::class),
                new Reference(ConfigurationProviderInterface::class),
                new Reference(GlobalCollectorRunner::class),
                new Reference(MetricRepositoryFactoryInterface::class),
                new Reference(DependencyGraphBuilder::class),
                new Reference(DelegatingLogger::class),
                new Reference(ProfilerHolder::class),
                new Reference(DuplicationDetector::class),
            ])
            ->setPublic(true);
        $container->setAlias(AnalysisPipelineInterface::class, AnalysisPipeline::class)
            ->setPublic(true);
    }
}
