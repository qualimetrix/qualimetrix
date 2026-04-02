<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ConfigurationHolder;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Configuration\RuleNamespaceExclusionProvider;
use Qualimetrix\Configuration\RuleOptionsFactory;
use Qualimetrix\Configuration\RuleOptionsRegistry;
use Qualimetrix\Configuration\RulePathExclusionProvider;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * Configures configuration holders and configuration pipeline.
 */
final class ConfigurationConfigurator implements ContainerConfiguratorInterface
{
    public function __construct(
        private readonly string $srcDir,
    ) {}

    public function configure(ContainerBuilder $container): void
    {
        $this->registerConfigurationHolder($container);
        $this->registerConfigurationPipeline($container);
    }

    /**
     * Registers configuration providers as mutable singletons.
     *
     * These are initialized with defaults and can be reconfigured at runtime
     * through setConfiguration()/setCliOptions() before rules are instantiated.
     */
    private function registerConfigurationHolder(ContainerBuilder $container): void
    {
        // RuleNamespaceExclusionProvider - shared between RuleOptionsRegistry and RuleExecutor
        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $container->register(RuleNamespaceExclusionProvider::class)
            ->setSynthetic(true)
            ->setPublic(true);
        $container->set(RuleNamespaceExclusionProvider::class, $exclusionProvider);

        // RulePathExclusionProvider - shared between RuleOptionsRegistry and RuleExecutor
        $pathExclusionProvider = new RulePathExclusionProvider();
        $container->register(RulePathExclusionProvider::class)
            ->setSynthetic(true)
            ->setPublic(true);
        $container->set(RulePathExclusionProvider::class, $pathExclusionProvider);

        // RuleOptionsRegistry - mutable storage, can be configured with CLI options at runtime
        $registry = new RuleOptionsRegistry($exclusionProvider, $pathExclusionProvider);
        $container->register(RuleOptionsRegistry::class)
            ->setSynthetic(true)
            ->setPublic(true);
        $container->set(RuleOptionsRegistry::class, $registry);

        // RuleOptionsFactory - stateless factory that reads from the registry
        $container->register(RuleOptionsFactory::class)
            ->setSynthetic(true)
            ->setPublic(true);
        $container->set(RuleOptionsFactory::class, new RuleOptionsFactory($registry));

        // ConfigurationHolder - mutable, configured at runtime with merged config
        $configProvider = new ConfigurationHolder();
        $configProvider->setConfiguration(new AnalysisConfiguration());

        $container->register(ConfigurationProviderInterface::class)
            ->setSynthetic(true)
            ->setPublic(true);
        $container->set(ConfigurationProviderInterface::class, $configProvider);
    }

    /**
     * Registers configuration pipeline with stages.
     *
     * Stages are auto-registered from src/Configuration/Pipeline/Stage/*
     * and automatically tagged via autoconfiguration.
     */
    private function registerConfigurationPipeline(ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator($this->srcDir));

        // Register ComposerReader (required by ComposerDiscoveryStage)
        $container->register(\Qualimetrix\Configuration\Discovery\ComposerReader::class)
            ->setAutowired(true);

        // Register PresetResolver (required by PresetStage)
        $container->register(\Qualimetrix\Configuration\Preset\PresetResolver::class)
            ->setAutowired(true);

        // Auto-register all configuration stages from src/Configuration/Pipeline/Stage/*
        // Classes implementing ConfigurationStageInterface will be auto-tagged via registerForAutoconfiguration
        $prototype = (new Definition())
            ->setAutoconfigured(true)
            ->setAutowired(true);
        $loader->registerClasses(
            $prototype,
            'Qualimetrix\\Configuration\\Pipeline\\Stage\\',
            $this->srcDir . '/Configuration/Pipeline/Stage/*',
            $this->srcDir . '/Configuration/Pipeline/Stage/*Interface.php',
        );

        // ConfigurationPipeline will be populated by ConfigurationStageCompilerPass
        $container->register(ConfigurationPipeline::class)
            ->setPublic(true);
    }
}
