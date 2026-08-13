<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\GlobalContextCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricCollectorInterface;

use Qualimetrix\Core\Coupling\FrameworkNamespacesHolder;
use Qualimetrix\Infrastructure\Parallel\FileProcessingTaskFactory;
use Qualimetrix\Infrastructure\Parallel\Strategy\AmphpParallelStrategy;
use Qualimetrix\Infrastructure\Parallel\Strategy\SequentialStrategy;
use Qualimetrix\Infrastructure\Parallel\Strategy\WorkerCountDetector;
use Qualimetrix\Metrics\Coupling\CouplingCollector;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Configures metric collectors and parallel processing infrastructure.
 */
final class CollectorConfigurator implements ContainerConfiguratorInterface
{
    public function __construct(
        private readonly string $srcDir,
    ) {}

    public function configure(ContainerBuilder $container): void
    {
        $this->registerCollectors($container);
        $this->registerParallel($container);
    }

    private function registerCollectors(ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator($this->srcDir));

        // Auto-register all metric collectors from src/Metrics/*
        // Classes implementing MetricCollectorInterface, DerivedCollectorInterface,
        // or GlobalContextCollectorInterface will be auto-tagged via registerForAutoconfiguration
        $prototype = (new Definition())->setAutoconfigured(true)->setAutowired(true);
        $loader->registerClasses(
            $prototype,
            'Qualimetrix\\Metrics\\',
            $this->srcDir . '/Metrics/*',
            $this->srcDir . '/Metrics/{Abstract*.php,*Interface.php,*Visitor.php,*Trait.php,*ClassData.php,*Metrics.php,*Calculator.php,*Detector.php,*Analyzer.php,*Resolver.php}',
        );

        // FrameworkNamespacesHolder — shared between RuntimeConfigurator and CouplingCollector
        $container->register(FrameworkNamespacesHolder::class)
            ->setPublic(true);

        // CouplingCollector needs FrameworkNamespacesHolder (set at runtime by RuntimeConfigurator)
        $container->getDefinition(CouplingCollector::class)
            ->setArgument('$frameworkNamespacesHolder', new Reference(FrameworkNamespacesHolder::class));

    }

    private function registerParallel(ContainerBuilder $container): void
    {
        // WorkerCountDetector for auto-detecting CPU cores
        $container->register(WorkerCountDetector::class);

        $container->register(FileProcessingTaskFactory::class);

        // AmphpParallelStrategy for parallel processing via amphp/parallel
        $container->register(AmphpParallelStrategy::class)
            ->setArgument('$fileProcessingTaskFactory', new Reference(FileProcessingTaskFactory::class));

        // SequentialStrategy as fallback
        $container->register(SequentialStrategy::class);

    }
}
