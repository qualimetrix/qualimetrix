<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Evidence\Cohesion\Configuration\LcomCollectionConfigurationResolver;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfigurationResolverInterface;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfigurationStoreInterface;
use Qualimetrix\Analysis\Evidence\Cohesion\Runtime\LcomCollectionConfigurationStore;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/** Registers the exact collector and rule roots owned by Cohesion. */
final class CohesionConfigurator implements ContainerConfiguratorInterface
{
    private const string NAMESPACE = 'Qualimetrix\\Analysis\\Evidence\\Cohesion\\';

    public function __construct(private readonly string $srcDir) {}

    public function configure(ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator($this->srcDir));
        $loader->registerClasses(
            (new Definition())->setAutoconfigured(true)->setAutowired(true),
            self::NAMESPACE,
            $this->srcDir . '/Analysis/Evidence/Cohesion/**/*Collector.php',
        );
        $loader->registerClasses(
            (new Definition())->setAutoconfigured(true)->setAutowired(false)->setLazy(true),
            self::NAMESPACE,
            $this->srcDir . '/Analysis/Evidence/Cohesion/**/*Rule.php',
        );

        $container->register(LcomCollectionConfigurationResolver::class);
        $container->setAlias(
            LcomCollectionConfigurationResolverInterface::class,
            LcomCollectionConfigurationResolver::class,
        );
        $container->register(LcomCollectionConfigurationStore::class)
            ->setArgument('$collectors', new TaggedIteratorArgument('qmx.cohesion.lcom_configurable_collector'));
        $container->setAlias(LcomCollectionConfigurationStoreInterface::class, LcomCollectionConfigurationStore::class);
    }
}
