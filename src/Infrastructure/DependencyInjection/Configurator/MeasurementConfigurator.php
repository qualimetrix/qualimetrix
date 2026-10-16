<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyTraversalParticipantInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedMetricExtractorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\FileMeasurementCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MeasurementAggregationInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReachCatalogInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryFactoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ProjectNamespaceResolverInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ProjectNamespaceSourceControlInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\DefaultMetricRepositoryFactory;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/** Registers Measurement internals behind their exact contracts and service IDs. */
final class MeasurementConfigurator implements ContainerConfiguratorInterface
{
    private const string COMPOSITE_COLLECTOR = 'qmx.measurement.file_collector';
    private const string COMPOSITE_COLLECTOR_CLASS = 'Qualimetrix\\Analysis\\Evidence\\Measurement\\FileMeasurement\\CompositeCollector';
    private const string DERIVED_METRIC_EXTRACTOR = 'qmx.measurement.derived_metric_extractor';
    private const string DERIVED_METRIC_EXTRACTOR_CLASS = 'Qualimetrix\\Analysis\\Evidence\\Measurement\\FileMeasurement\\DerivedMetricExtractor';
    private const string MEASUREMENT_AGGREGATION = 'qmx.measurement.aggregation';
    private const string MEASUREMENT_AGGREGATION_CLASS = 'Qualimetrix\\Analysis\\Evidence\\Measurement\\Aggregation\\MeasurementAggregationService';
    private const string IN_MEMORY_METRIC_REPOSITORY = 'qmx.measurement.in_memory_metric_repository';
    private const string IN_MEMORY_METRIC_REPOSITORY_CLASS = 'Qualimetrix\\Analysis\\Evidence\\Measurement\\Repository\\InMemoryMetricRepository';
    private const string METRIC_REPOSITORY_FACTORY = 'qmx.measurement.metric_repository_factory';
    private const string METRIC_REPOSITORY_FACTORY_CLASS = 'Qualimetrix\\Infrastructure\\DependencyInjection\\MeasurementRepositoryFactory';
    private const string DEFAULT_METRIC_REPOSITORY_FACTORY = 'qmx.measurement.default_metric_repository_factory';
    private const string PROJECT_NAMESPACE_RESOLVER = 'qmx.measurement.project_namespace_resolver';
    private const string PROJECT_NAMESPACE_RESOLVER_CLASS = 'Qualimetrix\\Analysis\\Evidence\\Measurement\\Namespace_\\ProjectNamespaceResolver';

    public function configure(ContainerBuilder $container): void
    {
        self::registerFileCollection($container);
        self::registerAggregation($container);
        self::registerRepository($container);
        self::registerNamespaceResolution($container);
    }

    private static function registerFileCollection(ContainerBuilder $container): void
    {
        $container->register(DeclarationRegistrarFactory::class, DeclarationRegistrarFactory::class);

        $container->register(self::COMPOSITE_COLLECTOR, self::COMPOSITE_COLLECTOR_CLASS)
            ->setArguments([
                '$collectors' => [],
                '$declarationRegistrarFactory' => new Reference(DeclarationRegistrarFactory::class),
                '$derivedCollectors' => [],
                '$dependencyTraversalParticipant' => new Reference(DependencyTraversalParticipantInterface::class),
            ])
            ->setPublic(true);
        $container->setAlias(FileMeasurementCollectorInterface::class, self::COMPOSITE_COLLECTOR);

        $container->register(self::DERIVED_METRIC_EXTRACTOR, self::DERIVED_METRIC_EXTRACTOR_CLASS)
            ->setArgument('$compositeCollector', new Reference(self::COMPOSITE_COLLECTOR));
        $container->setAlias(DerivedMetricExtractorInterface::class, self::DERIVED_METRIC_EXTRACTOR);
    }

    private static function registerAggregation(ContainerBuilder $container): void
    {
        $container->register(self::MEASUREMENT_AGGREGATION, self::MEASUREMENT_AGGREGATION_CLASS)
            ->setArguments([
                '$collectors' => [],
                '$fileCollector' => new Reference(FileMeasurementCollectorInterface::class),
                '$logger' => new Reference('Qualimetrix\\Infrastructure\\Logging\\DelegatingLogger'),
                '$profiler' => new Reference(ProfilerInterface::class),
            ]);
        $container->setAlias(MeasurementAggregationInterface::class, self::MEASUREMENT_AGGREGATION);
        $container->setAlias(MetricReachCatalogInterface::class, self::MEASUREMENT_AGGREGATION);
        $container->setAlias(MetricDefinitionCatalogInterface::class, self::MEASUREMENT_AGGREGATION);
    }

    private static function registerRepository(ContainerBuilder $container): void
    {
        $container->register(self::IN_MEMORY_METRIC_REPOSITORY, self::IN_MEMORY_METRIC_REPOSITORY_CLASS)
            ->setFactory([new Reference(self::METRIC_REPOSITORY_FACTORY), 'create']);
        $container->setAlias(MetricRepositoryInterface::class, self::IN_MEMORY_METRIC_REPOSITORY);

        $container->register(self::DEFAULT_METRIC_REPOSITORY_FACTORY, DefaultMetricRepositoryFactory::class);
        $container->register(self::METRIC_REPOSITORY_FACTORY, self::METRIC_REPOSITORY_FACTORY_CLASS)
            ->setArguments([
                '$measured' => new Reference(MetricDefinitionCatalogInterface::class),
                '$computed' => new Reference('Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\ComputedMetricDefinitionCatalogInterface'),
                '$storageFactory' => new Reference(self::DEFAULT_METRIC_REPOSITORY_FACTORY),
            ]);
        $container->setAlias(MetricRepositoryFactoryInterface::class, self::METRIC_REPOSITORY_FACTORY);
    }

    private static function registerNamespaceResolution(ContainerBuilder $container): void
    {
        $container->register(self::PROJECT_NAMESPACE_RESOLVER, self::PROJECT_NAMESPACE_RESOLVER_CLASS)
            ->setPublic(true);
        $container->setAlias(ProjectNamespaceResolverInterface::class, self::PROJECT_NAMESPACE_RESOLVER)
            ->setPublic(true);

        $container->setAlias(ProjectNamespaceSourceControlInterface::class, self::PROJECT_NAMESPACE_RESOLVER);
    }
}
