<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection;

use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfigurationProviderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CollectorRuntimeConfigurableInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\GlobalContextCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricCollectorInterface;
use Qualimetrix\Analysis\Run\Contract\FileSetInspectionParticipantInterface;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ChannelDeclarationCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\CollectorCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ConfigurationStageCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\FileSetInspectionParticipantCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\FormatterCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\GlobalCollectorCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ParallelCollectorClassesCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleOptionsCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleRegistryCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ThresholdValidatorMapCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\AnalysisConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ArchitectureConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\CircularDependencyConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\CodeSmellConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\CohesionConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\CollectorConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ComplexityConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ComputedMetricsConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ConfigurationConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\CoreServicesConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\CouplingConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\DependencyModelConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\DesignConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\DuplicationConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\FindingConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\MaintainabilityConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\MeasurementConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\OutputConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ParserConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\RuleConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\SecurityConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\SizeConfigurator;
use Qualimetrix\Reporting\Formatter\FormatterInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Unified factory for creating the DI container.
 *
 * This single container provides all services needed for both CLI and runtime:
 * - RuleRegistry with rule classes (for CLI option discovery)
 * - ConfigLoader for reading configuration files
 * - CheckCommand with injected dependencies
 * - All analysis services (Analyzer, Collectors, Rules, etc.)
 *
 * Runtime configuration is handled through TransitionalRuntimeConfigurationProviderInterface and
 * RuleOptionsRegistry, which can be configured after container creation but
 * before rules are instantiated (rules are lazy-loaded).
 *
 * Service registration is delegated to dedicated configurators, each responsible
 * for a cohesive group of services. Configurators are bootstrapping-code and
 * are instantiated manually (not via DI).
 */
final class ContainerFactory
{
    /**
     * Create a fully configured container.
     *
     * The container is created with default configuration. Runtime configuration
     * (from CLI or config file) should be set through:
     * - TransitionalRuntimeConfigurationProviderInterface::setConfiguration()
     * - RuleOptionsRegistry::setCliOptions()
     *
     * These must be called BEFORE rules are used (e.g., before Analyzer::analyze()).
     */
    public function create(): ContainerBuilder
    {
        $container = new ContainerBuilder();

        // Register autoconfiguration rules for interface tagging
        $this->registerAutoconfiguration($container);

        // Delegate service registration to configurators
        $srcDir = \dirname(__DIR__, 2); // src/

        $configurators = [
            new CoreServicesConfigurator(),
            new ConfigurationConfigurator($srcDir),
            new ParserConfigurator(),
            new CollectorConfigurator(),
            new CodeSmellConfigurator($srcDir),
            new CohesionConfigurator($srcDir),
            new ComplexityConfigurator($srcDir),
            new CouplingConfigurator($srcDir),
            new DesignConfigurator($srcDir),
            new MaintainabilityConfigurator($srcDir),
            new SecurityConfigurator($srcDir),
            new SizeConfigurator($srcDir),
            new MeasurementConfigurator(),
            new DependencyModelConfigurator(),
            new ComputedMetricsConfigurator(),
            new RuleConfigurator(),
            new FindingConfigurator(),
            new ArchitectureConfigurator($srcDir),
            new CircularDependencyConfigurator($srcDir),
            new DuplicationConfigurator($srcDir),
            new AnalysisConfigurator(),
            new OutputConfigurator($srcDir),
        ];

        foreach ($configurators as $configurator) {
            $configurator->configure($container);
        }

        // Add compiler passes
        $this->registerCompilerPasses($container);

        // Compile container
        $container->compile();

        return $container;
    }

    /**
     * Registers autoconfiguration rules for automatic interface tagging.
     *
     * These must be registered before any service definitions so that
     * registerClasses() can apply the tags automatically.
     */
    private function registerAutoconfiguration(ContainerBuilder $container): void
    {
        // Autoconfigure: all RuleInterface implementations get tagged and made lazy
        $ruleInterface = 'Qualimetrix\\Analysis\\Finding\\Rule\\RuleInterface';
        $container->registerForAutoconfiguration($ruleInterface)
            ->addTag(RuleCompilerPass::TAG)
            ->setLazy(true);

        // Autoconfigure: all collector interfaces get auto-tagged
        $container->registerForAutoconfiguration(MetricCollectorInterface::class)
            ->addTag(CollectorCompilerPass::TAG);

        $container->registerForAutoconfiguration(DerivedCollectorInterface::class)
            ->addTag(CollectorCompilerPass::TAG_DERIVED);

        $container->registerForAutoconfiguration(GlobalContextCollectorInterface::class)
            ->addTag(GlobalCollectorCompilerPass::TAG);

        $container->registerForAutoconfiguration(CollectorRuntimeConfigurableInterface::class)
            ->addTag('qmx.measurement.runtime_configurable_collector');

        $container->registerForAutoconfiguration(FileSetInspectionParticipantInterface::class)
            ->addTag(FileSetInspectionParticipantCompilerPass::TAG);

        // Autoconfigure: all formatters get auto-tagged
        $container->registerForAutoconfiguration(FormatterInterface::class)
            ->addTag(FormatterCompilerPass::TAG);

        // Configuration stages autoconfiguration
        $container->registerForAutoconfiguration('Qualimetrix\\Analysis\\Configuration\\Pipeline\\ConfigurationStageInterface')
            ->addTag(ConfigurationStageCompilerPass::TAG);

    }

    /**
     * Registers all compiler passes in the correct order.
     */
    private function registerCompilerPasses(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new CollectorCompilerPass());
        $container->addCompilerPass(new GlobalCollectorCompilerPass());
        $container->addCompilerPass(new ParallelCollectorClassesCompilerPass());
        $container->addCompilerPass(new FileSetInspectionParticipantCompilerPass());
        $container->addCompilerPass(new RuleRegistryCompilerPass());
        $container->addCompilerPass(new ChannelDeclarationCompilerPass());
        $container->addCompilerPass(new ThresholdValidatorMapCompilerPass());
        // RuleOptionsCompilerPass MUST run AFTER autoconfiguration (TYPE_OPTIMIZE)
        // but BEFORE RuleCompilerPass. Using TYPE_BEFORE_REMOVING with high priority.
        $container->addCompilerPass(
            new RuleOptionsCompilerPass(),
            PassConfig::TYPE_BEFORE_REMOVING,
            100, // High priority to run before RuleCompilerPass
        );
        $container->addCompilerPass(
            new RuleCompilerPass(),
            PassConfig::TYPE_BEFORE_REMOVING,
            50, // Lower priority, runs after RuleOptionsCompilerPass
        );
        $container->addCompilerPass(new FormatterCompilerPass());
        $container->addCompilerPass(new ConfigurationStageCompilerPass());
    }
}
