<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Evidence\Coupling\Contract\Configuration\CouplingConfiguratorInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/** Registers Coupling through its public configuration contract and exact roots. */
final class CouplingConfigurator implements ContainerConfiguratorInterface
{
    private const string NAMESPACE = 'Qualimetrix\\Analysis\\Evidence\\Coupling\\';
    private const string ANALYSIS = self::NAMESPACE . 'CouplingAnalysis';

    public function __construct(private readonly string $srcDir) {}

    public function configure(ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator($this->srcDir));
        $loader->registerClasses(
            (new Definition())->setAutoconfigured(true)->setAutowired(true),
            self::NAMESPACE,
            $this->srcDir . '/Analysis/Evidence/Coupling/**/*Collector.php',
        );
        $loader->registerClasses(
            (new Definition())->setAutoconfigured(true)->setAutowired(false)->setLazy(true),
            self::NAMESPACE,
            $this->srcDir . '/Analysis/Evidence/Coupling/**/*Rule.php',
        );

        $container->register(self::ANALYSIS, self::ANALYSIS);
        $container->setAlias(CouplingConfiguratorInterface::class, self::ANALYSIS)
            ->setPublic(true);
    }
}
