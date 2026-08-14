<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Configuration\Contract\Discovery\ComposerAutoloadPathReaderInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfiguration;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfigurationProviderInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Configures configuration holders and configuration pipeline.
 */
final class ConfigurationConfigurator implements ContainerConfiguratorInterface
{
    private const string PRESET_RESOLVER = 'qmx.configuration.preset_resolver';
    private const string CONFIGURATION_PIPELINE = 'qmx.configuration.pipeline';
    private const string CONFIGURATION_PIPELINE_CLASS = 'Qualimetrix\\Analysis\\Configuration\\Pipeline\\ConfigurationPipeline';
    private const string PRESET_RESOLVER_CLASS = 'Qualimetrix\\Analysis\\Configuration\\Preset\\PresetResolver';
    private const string RUNTIME_CONFIGURATION_HOLDER = 'qmx.configuration.transitional_runtime_configuration_holder';
    private const string RUNTIME_CONFIGURATION_HOLDER_CLASS = 'Qualimetrix\\Analysis\\Configuration\\Runtime\\TransitionalRuntimeConfigurationHolder';
    private const string YAML_CONFIG_LOADER = 'qmx.configuration.yaml_config_loader';
    private const string YAML_CONFIG_LOADER_CLASS = 'Qualimetrix\\Analysis\\Configuration\\Loader\\YamlConfigLoader';
    private const string COMPOSER_AUTOLOAD_READER = 'qmx.configuration.composer_autoload_reader';
    private const string COMPOSER_AUTOLOAD_READER_CLASS = 'Qualimetrix\\Analysis\\Configuration\\Discovery\\ComposerReader';

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
        // TransitionalRuntimeConfigurationHolder - mutable, configured at runtime with merged config
        $container->register(self::RUNTIME_CONFIGURATION_HOLDER, self::RUNTIME_CONFIGURATION_HOLDER_CLASS)
            ->addMethodCall('setConfiguration', [new TransitionalRuntimeConfiguration()])
            ->setPublic(true);
        $container->setAlias(
            TransitionalRuntimeConfigurationProviderInterface::class,
            self::RUNTIME_CONFIGURATION_HOLDER,
        )->setPublic(true);
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
        $container->register(self::COMPOSER_AUTOLOAD_READER, self::COMPOSER_AUTOLOAD_READER_CLASS)
            ->setAutowired(true);
        $container->setAlias(ComposerAutoloadPathReaderInterface::class, self::COMPOSER_AUTOLOAD_READER);

        // Register PresetResolver (required by PresetStage)
        $container->register(self::PRESET_RESOLVER, self::PRESET_RESOLVER_CLASS)
            ->setAutowired(true);

        $container->register(self::YAML_CONFIG_LOADER, self::YAML_CONFIG_LOADER_CLASS);
        $container->setAlias(
            'Qualimetrix\\Analysis\\Configuration\\Loader\\ConfigLoaderInterface',
            self::YAML_CONFIG_LOADER,
        );

        // Auto-register all configuration stages from src/Configuration/Pipeline/Stage/*
        // Classes implementing ConfigurationStageInterface will be auto-tagged via registerForAutoconfiguration
        $prototype = (new Definition())
            ->setAutoconfigured(true)
            ->setAutowired(true);
        $loader->registerClasses(
            $prototype,
            'Qualimetrix\\Analysis\\Configuration\\Pipeline\\Stage\\',
            $this->srcDir . '/Analysis/Configuration/Pipeline/Stage/*',
            $this->srcDir . '/Analysis/Configuration/Pipeline/Stage/*Interface.php',
        );
        $container->getDefinition('Qualimetrix\\Analysis\\Configuration\\Pipeline\\Stage\\PresetStage')
            ->setArgument('$resolver', new Reference(self::PRESET_RESOLVER));

        // ConfigurationPipeline exposes an ordered document. Capability-owned
        // parsing and warning delivery happen later, after runtime logging.
        $container->register(self::CONFIGURATION_PIPELINE, self::CONFIGURATION_PIPELINE_CLASS)
            ->setAutowired(true)
            ->setPublic(true);
        $container->setAlias(ConfigurationPipelineInterface::class, self::CONFIGURATION_PIPELINE)
            ->setPublic(true);
    }
}
