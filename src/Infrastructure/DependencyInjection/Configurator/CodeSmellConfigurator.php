<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/** Registers the exact collector and rule roots owned by CodeSmell. */
final class CodeSmellConfigurator implements ContainerConfiguratorInterface
{
    private const string NAMESPACE = 'Qualimetrix\\Analysis\\Evidence\\CodeSmell\\';

    public function __construct(private readonly string $srcDir) {}

    public function configure(ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator($this->srcDir));
        $loader->registerClasses(
            (new Definition())->setAutoconfigured(true)->setAutowired(true),
            self::NAMESPACE,
            $this->srcDir . '/Analysis/Evidence/CodeSmell/**/*Collector.php',
        );
        $loader->registerClasses(
            (new Definition())->setAutoconfigured(true)->setAutowired(false)->setLazy(true),
            self::NAMESPACE,
            $this->srcDir . '/Analysis/Evidence/CodeSmell/**/*Rule.php',
            $this->srcDir . '/Analysis/Evidence/CodeSmell/Abstract*Rule.php',
        );
    }
}
