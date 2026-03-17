<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\DependencyInjection\Configurator;

use AiMessDetector\Analysis\Collection\Dependency\DependencyGraphBuilder;
use AiMessDetector\Analysis\Collection\Dependency\DependencyVisitor;
use AiMessDetector\Analysis\Discovery\FileDiscoveryInterface;
use AiMessDetector\Analysis\Pipeline\AnalysisPipelineInterface;
use AiMessDetector\Baseline\BaselineGenerator;
use AiMessDetector\Baseline\BaselineLoader;
use AiMessDetector\Baseline\BaselineWriter;
use AiMessDetector\Baseline\Suppression\SuppressionFilter;
use AiMessDetector\Baseline\ViolationHasher;
use AiMessDetector\Configuration\ComputedMetricsConfigResolver;
use AiMessDetector\Configuration\ConfigurationProviderInterface;
use AiMessDetector\Configuration\Loader\ConfigLoaderInterface;
use AiMessDetector\Configuration\Loader\YamlConfigLoader;
use AiMessDetector\Configuration\Pipeline\ConfigurationPipeline;
use AiMessDetector\Configuration\RuleOptionsFactory;
use AiMessDetector\Core\Ast\FileParserInterface;
use AiMessDetector\Core\Profiler\ProfilerHolder;
use AiMessDetector\Infrastructure\Cache\CacheFactory;
use AiMessDetector\Infrastructure\Console\Command\BaselineCleanupCommand;
use AiMessDetector\Infrastructure\Console\Command\CheckCommand;
use AiMessDetector\Infrastructure\Console\Command\GraphExportCommand;
use AiMessDetector\Infrastructure\Console\Command\HookInstallCommand;
use AiMessDetector\Infrastructure\Console\Command\HookStatusCommand;
use AiMessDetector\Infrastructure\Console\Command\HookUninstallCommand;
use AiMessDetector\Infrastructure\Console\Command\RulesCommand;
use AiMessDetector\Infrastructure\Console\Progress\ProgressReporterHolder;
use AiMessDetector\Infrastructure\Console\ResultPresenter;
use AiMessDetector\Infrastructure\Console\RuntimeConfigurator;
use AiMessDetector\Infrastructure\Console\ViolationFilterPipeline;
use AiMessDetector\Infrastructure\Git\GitRepositoryLocator;
use AiMessDetector\Infrastructure\Logging\DelegatingLogger;
use AiMessDetector\Infrastructure\Logging\LoggerFactory;
use AiMessDetector\Infrastructure\Logging\LoggerHolder;
use AiMessDetector\Infrastructure\Rule\RuleRegistryInterface;
use AiMessDetector\Reporting\Filter\ViolationFilter;
use AiMessDetector\Reporting\Formatter\FormatterRegistry;
use AiMessDetector\Reporting\Formatter\FormatterRegistryInterface;
use AiMessDetector\Reporting\Formatter\Support\DetailedViolationRenderer;
use AiMessDetector\Reporting\Health\SummaryEnricher;
use AiMessDetector\Reporting\Profile\ProfileSummaryRenderer;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Configures formatters, baseline services, and CLI commands.
 */
final class OutputConfigurator implements ContainerConfiguratorInterface
{
    public function __construct(
        private readonly string $srcDir,
    ) {}

    public function configure(ContainerBuilder $container): void
    {
        $this->registerFormatters($container);
        $this->registerBaseline($container);
        $this->registerCli($container);
    }

    private function registerFormatters(ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator($this->srcDir));

        // Auto-register debt calculation services from src/Reporting/Debt/*
        $debtPrototype = (new Definition())->setAutoconfigured(true)->setAutowired(true);
        $loader->registerClasses(
            $debtPrototype,
            'AiMessDetector\\Reporting\\Debt\\',
            $this->srcDir . '/Reporting/Debt/*',
        );

        // Auto-register all formatters from src/Reporting/Formatter/ (recursive)
        // Classes implementing FormatterInterface will be auto-tagged via registerForAutoconfiguration
        // Exclude Support/ (utility classes, some not DI-compatible: AnsiColor takes bool $enabled)
        $prototype = (new Definition())->setAutoconfigured(true)->setAutowired(true);
        $loader->registerClasses(
            $prototype,
            'AiMessDetector\\Reporting\\Formatter\\',
            $this->srcDir . '/Reporting/Formatter/{*,**/*}',
            $this->srcDir . '/Reporting/Formatter/{*Interface.php,FormatterRegistry.php,Support/**}',
        );

        // Auto-register health scoring services from src/Reporting/Health/
        // Exclude VOs (scalar constructors, always instantiated via `new`)
        $healthPrototype = (new Definition())->setAutoconfigured(true)->setAutowired(true);
        $loader->registerClasses(
            $healthPrototype,
            'AiMessDetector\\Reporting\\Health\\',
            $this->srcDir . '/Reporting/Health/*',
            $this->srcDir . '/Reporting/Health/{HealthScore.php,WorstOffender.php,DecompositionItem.php}',
        );

        // ViolationFilter (shared filtering logic for formatters)
        $container->register(ViolationFilter::class);

        // DetailedViolationRenderer (in Formatter/Support/, excluded from formatter glob)
        $container->register(DetailedViolationRenderer::class)
            ->setAutowired(true);

        // FormatterRegistry will be populated by compiler pass
        $container->register(FormatterRegistry::class)
            ->setArguments([[]]);

        $container->setAlias(FormatterRegistryInterface::class, FormatterRegistry::class)
            ->setPublic(true);
    }

    private function registerBaseline(ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator($this->srcDir));

        // Auto-register all baseline services from src/Baseline/*
        // Excludes: Value Objects (Baseline, BaselineEntry, Suppression)
        $prototype = (new Definition())->setAutoconfigured(true)->setAutowired(true);
        $loader->registerClasses(
            $prototype,
            'AiMessDetector\\Baseline\\',
            $this->srcDir . '/Baseline/*',
            $this->srcDir . '/Baseline/{Baseline.php,BaselineEntry.php,Suppression/Suppression.php}',
        );
    }

    private function registerCli(ContainerBuilder $container): void
    {
        // ConfigLoader
        $container->register(YamlConfigLoader::class);
        $container->setAlias(ConfigLoaderInterface::class, YamlConfigLoader::class);

        // ViolationFilterPipeline
        $container->register(ViolationFilterPipeline::class)
            ->setArguments([
                new Reference(BaselineLoader::class),
                new Reference(ViolationHasher::class),
                new Reference(SuppressionFilter::class),
                new Reference(ConfigurationProviderInterface::class),
            ]);

        // RuntimeConfigurator for runtime service configuration
        $container->register(RuntimeConfigurator::class)
            ->setArguments([
                new Reference(LoggerFactory::class),
                new Reference(LoggerHolder::class),
                new Reference(ProgressReporterHolder::class),
                new Reference(ProfilerHolder::class),
                new Reference(ConfigurationProviderInterface::class),
                new Reference(RuleOptionsFactory::class),
                new Reference(RuleRegistryInterface::class),
                new Reference(CacheFactory::class),
                new Reference(ComputedMetricsConfigResolver::class),
            ]);

        // ComputedMetricsConfigResolver
        $container->register(ComputedMetricsConfigResolver::class);

        // ProfileSummaryRenderer (stateless, no dependencies)
        $container->register(ProfileSummaryRenderer::class);

        // ResultPresenter for formatting/output of results and profiler export
        $container->register(ResultPresenter::class)
            ->setArguments([
                new Reference(FormatterRegistryInterface::class),
                new Reference(ProfilerHolder::class),
                new Reference(BaselineGenerator::class),
                new Reference(BaselineWriter::class),
                new Reference(ConfigurationProviderInterface::class),
                new Reference(SummaryEnricher::class),
                new Reference(ProfileSummaryRenderer::class),
            ]);

        // CheckCommand with all dependencies injected
        $container->register(CheckCommand::class)
            ->setArguments([
                new Reference(RuleRegistryInterface::class),
                new Reference(AnalysisPipelineInterface::class),
                new Reference(CacheFactory::class),
                new Reference(ViolationFilterPipeline::class),
                new Reference(ConfigurationPipeline::class),
                new Reference(RuntimeConfigurator::class),
                new Reference(ResultPresenter::class),
            ])
            ->setPublic(true);

        // BaselineCleanupCommand
        $container->register(BaselineCleanupCommand::class)
            ->setArguments([
                new Reference(BaselineLoader::class),
                new Reference(BaselineWriter::class),
            ])
            ->setPublic(true);

        // GitRepositoryLocator (shared by hook commands)
        $container->register(GitRepositoryLocator::class);

        // HookInstallCommand
        $container->register(HookInstallCommand::class)
            ->setArguments([
                new Reference(GitRepositoryLocator::class),
            ])
            ->setPublic(true);

        // HookUninstallCommand
        $container->register(HookUninstallCommand::class)
            ->setArguments([
                new Reference(GitRepositoryLocator::class),
            ])
            ->setPublic(true);

        // HookStatusCommand
        $container->register(HookStatusCommand::class)
            ->setArguments([
                new Reference(GitRepositoryLocator::class),
            ])
            ->setPublic(true);

        // RulesCommand
        $container->register(RulesCommand::class)
            ->setArguments([
                new Reference(RuleRegistryInterface::class),
            ])
            ->setPublic(true);

        // GraphExportCommand
        // Note: DependencyGraphBuilder is already registered in AnalysisConfigurator
        $container->register(GraphExportCommand::class)
            ->setArguments([
                new Reference(FileDiscoveryInterface::class),
                new Reference(FileParserInterface::class),
                new Reference(DependencyVisitor::class),
                new Reference(DependencyGraphBuilder::class),
                new Reference(DelegatingLogger::class),
            ])
            ->setPublic(true);
    }
}
