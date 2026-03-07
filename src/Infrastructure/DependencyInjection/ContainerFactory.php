<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\DependencyInjection;

use AiMessDetector\Configuration\Pipeline\Stage\ConfigurationStageInterface;
use AiMessDetector\Core\Metric\DerivedCollectorInterface;
use AiMessDetector\Core\Metric\GlobalContextCollectorInterface;
use AiMessDetector\Core\Metric\MetricCollectorInterface;
use AiMessDetector\Core\Rule\RuleInterface;
use AiMessDetector\Infrastructure\DependencyInjection\CompilerPass\CollectorCompilerPass;
use AiMessDetector\Infrastructure\DependencyInjection\CompilerPass\ConfigurationStageCompilerPass;
use AiMessDetector\Infrastructure\DependencyInjection\CompilerPass\FormatterCompilerPass;
use AiMessDetector\Infrastructure\DependencyInjection\CompilerPass\GlobalCollectorCompilerPass;
use AiMessDetector\Infrastructure\DependencyInjection\CompilerPass\ParallelCollectorClassesCompilerPass;
use AiMessDetector\Infrastructure\DependencyInjection\CompilerPass\RuleCompilerPass;
use AiMessDetector\Infrastructure\DependencyInjection\CompilerPass\RuleOptionsCompilerPass;
use AiMessDetector\Infrastructure\DependencyInjection\CompilerPass\RuleRegistryCompilerPass;
use AiMessDetector\Infrastructure\DependencyInjection\Configurator\AnalysisConfigurator;
use AiMessDetector\Infrastructure\DependencyInjection\Configurator\CollectorConfigurator;
use AiMessDetector\Infrastructure\DependencyInjection\Configurator\ConfigurationConfigurator;
use AiMessDetector\Infrastructure\DependencyInjection\Configurator\CoreServicesConfigurator;
use AiMessDetector\Infrastructure\DependencyInjection\Configurator\OutputConfigurator;
use AiMessDetector\Infrastructure\DependencyInjection\Configurator\ParserConfigurator;
use AiMessDetector\Infrastructure\DependencyInjection\Configurator\RuleConfigurator;
use AiMessDetector\Reporting\Formatter\FormatterInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Unified factory for creating the DI container.
 *
 * This single container provides all services needed for both CLI and analysis:
 * - RuleRegistry with rule classes (for CLI option discovery)
 * - ConfigLoader for reading configuration files
 * - AnalyzeCommand with injected dependencies
 * - All analysis services (Analyzer, Collectors, Rules, etc.)
 *
 * Runtime configuration is handled through ConfigurationProviderInterface and
 * RuleOptionsFactory, which can be configured after container creation but
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
     * - ConfigurationProviderInterface::setConfiguration()
     * - RuleOptionsFactory::setCliOptions()
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
            new CollectorConfigurator($srcDir),
            new RuleConfigurator($srcDir),
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
        $container->registerForAutoconfiguration(RuleInterface::class)
            ->addTag(RuleCompilerPass::TAG)
            ->setLazy(true);

        // Autoconfigure: all collector interfaces get auto-tagged
        $container->registerForAutoconfiguration(MetricCollectorInterface::class)
            ->addTag(CollectorCompilerPass::TAG);

        $container->registerForAutoconfiguration(DerivedCollectorInterface::class)
            ->addTag(CollectorCompilerPass::TAG_DERIVED);

        $container->registerForAutoconfiguration(GlobalContextCollectorInterface::class)
            ->addTag(GlobalCollectorCompilerPass::TAG);

        // Autoconfigure: all formatters get auto-tagged
        $container->registerForAutoconfiguration(FormatterInterface::class)
            ->addTag(FormatterCompilerPass::TAG);

        // Configuration stages autoconfiguration
        $container->registerForAutoconfiguration(ConfigurationStageInterface::class)
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
        $container->addCompilerPass(new RuleRegistryCompilerPass());
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
