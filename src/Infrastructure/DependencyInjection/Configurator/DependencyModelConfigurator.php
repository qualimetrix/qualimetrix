<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfigurationStoreInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyTraversalParticipantInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\Strategy\StrategySelectorInterface;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationStoreInterface;
use Qualimetrix\Infrastructure\Logging\DelegatingLogger;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationStoreInterface;
use Qualimetrix\Infrastructure\Parallel\Strategy\StrategySelector;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/** Configures the DependencyModel extraction seam and its worker metadata. */
final class DependencyModelConfigurator implements ContainerConfiguratorInterface
{
    private const string AMPHP_PARALLEL_STRATEGY_SERVICE_ID = 'Qualimetrix\\Infrastructure\\Parallel\\Strategy\\AmphpParallelStrategy';
    private const string SEQUENTIAL_STRATEGY_SERVICE_ID = 'Qualimetrix\\Infrastructure\\Parallel\\Strategy\\SequentialStrategy';
    private const string WORKER_COUNT_DETECTOR_SERVICE_ID = 'Qualimetrix\\Infrastructure\\Parallel\\Strategy\\WorkerCountDetector';
    private const string FILE_PROCESSING_TASK_FACTORY_SERVICE_ID = 'Qualimetrix\\Infrastructure\\Parallel\\FileProcessingTaskFactory';
    private const string TRAVERSAL_PARTICIPANT_SERVICE_ID = 'qmx.dependency_model.traversal_participant';
    private const string TRAVERSAL_PARTICIPANT_CLASS = 'Qualimetrix\\Analysis\\Evidence\\DependencyModel\\Extraction\\DependencyVisitor';

    public function configure(ContainerBuilder $container): void
    {
        $container->register(self::TRAVERSAL_PARTICIPANT_SERVICE_ID, self::TRAVERSAL_PARTICIPANT_CLASS);
        $container->setAlias(
            DependencyTraversalParticipantInterface::class,
            self::TRAVERSAL_PARTICIPANT_SERVICE_ID,
        )->setPublic(true);

        $container->getDefinition(self::FILE_PROCESSING_TASK_FACTORY_SERVICE_ID)
            ->setArgument(
                '$lcomConfigurationStore',
                new Reference(LcomCollectionConfigurationStoreInterface::class),
            )
            ->setArgument('$dependencyTraversalParticipantClass', self::TRAVERSAL_PARTICIPANT_CLASS);

        $container->register(StrategySelector::class)
            ->setArguments([
                '$amphpStrategy' => new Reference(self::AMPHP_PARALLEL_STRATEGY_SERVICE_ID),
                '$sequentialStrategy' => new Reference(self::SEQUENTIAL_STRATEGY_SERVICE_ID),
                '$configurationStore' => new Reference(ParallelConfigurationStoreInterface::class),
                '$cacheConfigurationStore' => new Reference(CacheConfigurationStoreInterface::class),
                '$workerCountDetector' => new Reference(self::WORKER_COUNT_DETECTOR_SERVICE_ID),
                '$logger' => new Reference(DelegatingLogger::class),
            ]);
        $container->setAlias(StrategySelectorInterface::class, StrategySelector::class);
    }
}
