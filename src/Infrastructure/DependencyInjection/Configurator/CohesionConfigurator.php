<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Evidence\Cohesion\Configuration\LcomCollectionConfigurationResolver;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfigurationResolverInterface;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfigurationStoreInterface;
use Qualimetrix\Analysis\Evidence\Cohesion\Runtime\LcomCollectionConfigurationStore;
use Qualimetrix\Infrastructure\DependencyInjection\Registration\EvidenceRegistration;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Registers the exact collector and rule roots owned by Cohesion. */
final class CohesionConfigurator implements ContainerConfiguratorInterface
{
    private const string NAMESPACE = 'Qualimetrix\\Analysis\\Evidence\\Cohesion\\';

    public function __construct(private readonly string $srcDir) {}

    public function configure(ContainerBuilder $container): void
    {
        $loader = EvidenceRegistration::loader($container, $this->srcDir);
        $loader->registerClasses(
            EvidenceRegistration::collectors(),
            self::NAMESPACE,
            $this->srcDir . '/Analysis/Evidence/Cohesion/**/*Collector.php',
        );
        $loader->registerClasses(
            EvidenceRegistration::rules(),
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
