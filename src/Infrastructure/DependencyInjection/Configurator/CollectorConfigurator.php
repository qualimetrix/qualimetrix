<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Infrastructure\Parallel\Configuration\ParallelConfigurationResolver;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationResolverInterface;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationStoreInterface;
use Qualimetrix\Infrastructure\Parallel\FileProcessingTaskFactory;
use Qualimetrix\Infrastructure\Parallel\Runtime\ParallelConfigurationStore;
use Qualimetrix\Infrastructure\Parallel\Strategy\AmphpParallelStrategy;
use Qualimetrix\Infrastructure\Parallel\Strategy\SequentialStrategy;
use Qualimetrix\Infrastructure\Parallel\Strategy\WorkerCountDetector;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Configures parallel processing infrastructure.
 */
final class CollectorConfigurator implements ContainerConfiguratorInterface
{
    public function configure(ContainerBuilder $container): void
    {
        $this->registerParallel($container);
    }

    private function registerParallel(ContainerBuilder $container): void
    {
        $container->register(ParallelConfigurationStore::class);
        $container->setAlias(ParallelConfigurationStoreInterface::class, ParallelConfigurationStore::class);
        $container->register(ParallelConfigurationResolver::class);
        $container->setAlias(ParallelConfigurationResolverInterface::class, ParallelConfigurationResolver::class);

        // WorkerCountDetector for auto-detecting CPU cores
        $container->register(WorkerCountDetector::class);

        $container->register(FileProcessingTaskFactory::class);

        // AmphpParallelStrategy for parallel processing via amphp/parallel
        $container->register(AmphpParallelStrategy::class)
            ->setArgument('$fileProcessingTaskFactory', new Reference(FileProcessingTaskFactory::class));

        // SequentialStrategy as fallback
        $container->register(SequentialStrategy::class)
            ->setArgument('$profiler', new Reference(ProfilerInterface::class));

    }
}
