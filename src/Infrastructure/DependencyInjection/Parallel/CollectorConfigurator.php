<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Parallel;

use Closure;

use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionDocumentFormsInterface;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Infrastructure\Console\Refusal\OutOfMemoryHint;
use Qualimetrix\Infrastructure\Console\Terminal\Diagnostics\ErrorStream;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ContainerConfiguratorInterface;
use Qualimetrix\Infrastructure\Logging\DelegatingLogger;
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
        $container->register(ParallelConfigurationResolver::class)->setAutoconfigured(true);
        $container->setAlias(ParallelConfigurationResolverInterface::class, ParallelConfigurationResolver::class);

        // WorkerCountDetector for auto-detecting CPU cores
        $container->register(WorkerCountDetector::class);

        $container->register(FileProcessingTaskFactory::class)
            ->setArgument('$documentForms', new Reference(RuleOptionDocumentFormsInterface::class));
        $container->register('parallel.exhaustion-cleanup', Closure::class)
            ->setFactory([Closure::class, 'fromCallable'])
            ->setArguments([[new Reference(OutOfMemoryHint::class), 'onExhaustion']]);

        // AmphpParallelStrategy for parallel processing via amphp/parallel
        // The logger is what makes the parallel path observable. The selector
        // logs its decision before the strategy's own four fallbacks -- the
        // file-count threshold among them -- so without this the only line
        // saying "parallel" is written while sequential is still possible.
        $container->register(AmphpParallelStrategy::class)
            ->setArguments([
                '$fileProcessingTaskFactory' => new Reference(FileProcessingTaskFactory::class),
                '$logger' => new Reference(DelegatingLogger::class),
                '$sequentialStrategy' => new Reference(SequentialStrategy::class),
                '$profiler' => new Reference(ProfilerInterface::class),
                '$errorStream' => new Reference(ErrorStream::class),
                '$registerExhaustion' => new Reference('parallel.exhaustion-cleanup'),
            ]);

        // SequentialStrategy as fallback
        $container->register(SequentialStrategy::class)
            ->setArgument('$profiler', new Reference(ProfilerInterface::class));

    }
}
