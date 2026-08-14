<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/** Registers circular-dependency evidence and its owned rule. */
final class CircularDependencyConfigurator implements ContainerConfiguratorInterface
{
    private const string CIRCULAR_DEPENDENCY_ANALYSIS = 'Qualimetrix\\Analysis\\Evidence\\CircularDependency\\CircularDependencyAnalysis';
    private const string CIRCULAR_DEPENDENCY_DETECTOR = 'Qualimetrix\\Analysis\\Evidence\\CircularDependency\\CircularDependencyDetector';

    public function __construct(
        private readonly string $srcDir,
    ) {}

    public function configure(ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator($this->srcDir));
        $rules = (new Definition())
            ->setAutoconfigured(true)
            ->setAutowired(false)
            ->setLazy(true);
        $loader->registerClasses(
            $rules,
            'Qualimetrix\\Analysis\\Evidence\\CircularDependency\\',
            $this->srcDir . '/Analysis/Evidence/CircularDependency/*Rule.php',
        );

        $container->register(self::CIRCULAR_DEPENDENCY_DETECTOR);
        $container->register(self::CIRCULAR_DEPENDENCY_ANALYSIS)
            ->setAutowired(true);
        $container->setAlias(CircularDependencyPreparationInterface::class, self::CIRCULAR_DEPENDENCY_ANALYSIS)
            ->setPublic(true);
    }
}
