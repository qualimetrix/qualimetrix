<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Collection\Dependency\DependencyResolver;
use Qualimetrix\Analysis\Collection\Dependency\DependencyVisitor;
use Qualimetrix\Analysis\Collection\Metric\CompositeCollector;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Infrastructure\Logging\DelegatingLogger;
use Qualimetrix\Infrastructure\Parallel\Strategy\AmphpParallelStrategy;
use Qualimetrix\Infrastructure\Parallel\Strategy\SequentialStrategy;
use Qualimetrix\Infrastructure\Parallel\Strategy\StrategySelector;
use Qualimetrix\Infrastructure\Parallel\Strategy\WorkerCountDetector;
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
            $this->srcDir . '/Metrics/{Abstract*.php,*Interface.php,*Visitor.php,*Trait.php,*ClassData.php,*Metrics.php,*Calculator.php}',
        );

        // Auto-register global context collectors from src/Metrics/Coupling/*
        // (These implement GlobalContextCollectorInterface and are auto-tagged)

        // DependencyResolver for resolving class names to FQN
        $container->register(DependencyResolver::class);

        // DependencyVisitor for collecting dependencies during AST traversal
        $container->register(DependencyVisitor::class)
            ->setArguments([
                new Reference(DependencyResolver::class),
            ]);

        // CompositeCollector will be populated by compiler pass
        // Also receives DependencyVisitor for unified AST traversal (metrics + dependencies)
        $container->register(CompositeCollector::class)
            ->setArguments([[], []])
            ->addMethodCall('setDependencyVisitor', [new Reference(DependencyVisitor::class)])
            ->setPublic(true);
    }

    private function registerParallel(ContainerBuilder $container): void
    {
        // WorkerCountDetector for auto-detecting CPU cores
        $container->register(WorkerCountDetector::class);

        // AmphpParallelStrategy for parallel processing via amphp/parallel
        $container->register(AmphpParallelStrategy::class);

        // SequentialStrategy as fallback
        $container->register(SequentialStrategy::class);

        // StrategySelector chooses and configures best available strategy
        $container->register(StrategySelector::class)
            ->setArguments([
                new Reference(AmphpParallelStrategy::class),
                new Reference(SequentialStrategy::class),
                new Reference(ConfigurationProviderInterface::class),
                new Reference(WorkerCountDetector::class),
                new Reference(DelegatingLogger::class),
            ]);
    }
}
