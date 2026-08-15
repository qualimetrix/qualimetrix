<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/** Registers the exact collector and rule roots owned by Security. */
final class SecurityConfigurator implements ContainerConfiguratorInterface
{
    private const string NAMESPACE = 'Qualimetrix\\Analysis\\Evidence\\Security\\';

    public function __construct(private readonly string $srcDir) {}

    public function configure(ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator($this->srcDir));
        $loader->registerClasses(
            (new Definition())->setAutoconfigured(true)->setAutowired(true),
            self::NAMESPACE,
            $this->srcDir . '/Analysis/Evidence/Security/**/*Collector.php',
        );
        $loader->registerClasses(
            (new Definition())->setAutoconfigured(true)->setAutowired(false)->setLazy(true),
            self::NAMESPACE,
            $this->srcDir . '/Analysis/Evidence/Security/**/*Rule.php',
            $this->srcDir . '/Analysis/Evidence/Security/Abstract*Rule.php',
        );
    }
}
