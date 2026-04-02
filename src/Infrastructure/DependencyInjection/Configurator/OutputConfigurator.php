<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Collection\Dependency\DependencyGraphBuilder;
use Qualimetrix\Analysis\Collection\Dependency\DependencyVisitor;
use Qualimetrix\Analysis\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Baseline\BaselineGenerator;
use Qualimetrix\Baseline\BaselineLoader;
use Qualimetrix\Baseline\BaselineWriter;
use Qualimetrix\Baseline\Suppression\SuppressionFilter;
use Qualimetrix\Baseline\ViolationHasher;
use Qualimetrix\Configuration\ComputedMetricFormulaValidator;
use Qualimetrix\Configuration\ComputedMetricsConfigResolver;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Configuration\HealthFormulaExcluder;
use Qualimetrix\Configuration\Loader\ConfigLoaderInterface;
use Qualimetrix\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Configuration\RuleOptionsRegistry;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Core\Coupling\FrameworkNamespacesHolder;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Console\BaselinePresenter;
use Qualimetrix\Infrastructure\Console\Command\BaselineCleanupCommand;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\Command\GraphExportCommand;
use Qualimetrix\Infrastructure\Console\Command\HookInstallCommand;
use Qualimetrix\Infrastructure\Console\Command\HookStatusCommand;
use Qualimetrix\Infrastructure\Console\Command\HookUninstallCommand;
use Qualimetrix\Infrastructure\Console\Command\RulesCommand;
use Qualimetrix\Infrastructure\Console\ExitCodeResolver;
use Qualimetrix\Infrastructure\Console\FormatterContextFactory;
use Qualimetrix\Infrastructure\Console\ProfilePresenter;
use Qualimetrix\Infrastructure\Console\Progress\ProgressReporterHolder;
use Qualimetrix\Infrastructure\Console\ResultPresenter;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
use Qualimetrix\Infrastructure\Console\ViolationFilterOrchestrator;
use Qualimetrix\Infrastructure\Console\ViolationFilterPipeline;
use Qualimetrix\Infrastructure\Git\GitRepositoryLocator;
use Qualimetrix\Infrastructure\Logging\DelegatingLogger;
use Qualimetrix\Infrastructure\Logging\LoggerFactory;
use Qualimetrix\Infrastructure\Logging\LoggerHolder;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Qualimetrix\Reporting\Filter\ViolationFilter;
use Qualimetrix\Reporting\Formatter\FormatterRegistry;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use Qualimetrix\Reporting\Formatter\Support\DetailedViolationRenderer;
use Qualimetrix\Reporting\Health\SummaryEnricher;
use Qualimetrix\Reporting\Profile\ProfileSummaryRenderer;
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
            'Qualimetrix\\Reporting\\Debt\\',
            $this->srcDir . '/Reporting/Debt/*',
        );

        // Auto-register all formatters from src/Reporting/Formatter/ (recursive)
        // Classes implementing FormatterInterface will be auto-tagged via registerForAutoconfiguration
        // Exclude Support/ (utility classes, some not DI-compatible: AnsiColor takes bool $enabled)
        $prototype = (new Definition())->setAutoconfigured(true)->setAutowired(true);
        $loader->registerClasses(
            $prototype,
            'Qualimetrix\\Reporting\\Formatter\\',
            $this->srcDir . '/Reporting/Formatter/{*,**/*}',
            $this->srcDir . '/Reporting/Formatter/{*Interface.php,FormatterRegistry.php,Support/**}',
        );

        // Auto-register health scoring services from src/Reporting/Health/
        // Exclude VOs (scalar constructors, always instantiated via `new`)
        $healthPrototype = (new Definition())->setAutoconfigured(true)->setAutowired(true);
        $loader->registerClasses(
            $healthPrototype,
            'Qualimetrix\\Reporting\\Health\\',
            $this->srcDir . '/Reporting/Health/*',
            $this->srcDir . '/Reporting/Health/{HealthScore.php,WorstOffender.php,DecompositionItem.php}',
        );

        // Auto-register impact calculation services from src/Reporting/Impact/
        // Exclude VOs (RankedIssue)
        $impactPrototype = (new Definition())->setAutoconfigured(true)->setAutowired(true);
        $loader->registerClasses(
            $impactPrototype,
            'Qualimetrix\\Reporting\\Impact\\',
            $this->srcDir . '/Reporting/Impact/*',
            $this->srcDir . '/Reporting/Impact/RankedIssue.php',
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
            'Qualimetrix\\Baseline\\',
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
                new Reference(RuleOptionsRegistry::class),
                new Reference(RuleRegistryInterface::class),
                new Reference(CacheFactory::class),
                new Reference(ComputedMetricsConfigResolver::class),
                new Reference(HealthFormulaExcluder::class),
                new Reference(FrameworkNamespacesHolder::class),
            ]);

        // HealthFormulaExcluder for exclude-health formula rebuilding
        $container->register(HealthFormulaExcluder::class)
            ->setArguments([
                new Reference(DelegatingLogger::class),
            ]);

        // ComputedMetricFormulaValidator (validates expression syntax, references, circular deps)
        $container->register(ComputedMetricFormulaValidator::class);

        // ComputedMetricsConfigResolver
        $container->register(ComputedMetricsConfigResolver::class)
            ->setArguments([
                new Reference(ComputedMetricFormulaValidator::class),
            ]);

        // ProfileSummaryRenderer (stateless, no dependencies)
        $container->register(ProfileSummaryRenderer::class);

        // ProfilePresenter for profiling output
        $container->register(ProfilePresenter::class)
            ->setArguments([
                new Reference(ProfilerHolder::class),
                new Reference(ProfileSummaryRenderer::class),
            ]);

        // FormatterContextFactory (pure logic, no dependencies)
        $container->register(FormatterContextFactory::class);

        // ExitCodeResolver for determining process exit code from violations
        $container->register(ExitCodeResolver::class)
            ->setArguments([
                new Reference(ConfigurationProviderInterface::class),
            ]);

        // BaselinePresenter for baseline generation
        $container->register(BaselinePresenter::class)
            ->setArguments([
                new Reference(BaselineGenerator::class),
                new Reference(BaselineWriter::class),
                new Reference(ConfigurationProviderInterface::class),
            ]);

        // ViolationFilter for --namespace/--class drill-down
        $container->register(ViolationFilter::class);

        // ResultPresenter for formatting/output of analysis results
        $container->register(ResultPresenter::class)
            ->setArguments([
                new Reference(FormatterRegistryInterface::class),
                new Reference(ProfilerHolder::class),
                new Reference(ConfigurationProviderInterface::class),
                new Reference(SummaryEnricher::class),
                new Reference(ProfilePresenter::class),
                new Reference(ExitCodeResolver::class),
                new Reference(ViolationFilter::class),
                new Reference(FormatterContextFactory::class),
            ]);

        // ViolationFilterOrchestrator
        $container->register(ViolationFilterOrchestrator::class)
            ->setArguments([
                new Reference(ViolationFilterPipeline::class),
            ]);

        // CheckCommand with all dependencies injected
        $container->register(CheckCommand::class)
            ->setArguments([
                new Reference(RuleRegistryInterface::class),
                new Reference(AnalysisPipelineInterface::class),
                new Reference(CacheFactory::class),
                new Reference(ViolationFilterOrchestrator::class),
                new Reference(ConfigurationPipeline::class),
                new Reference(RuntimeConfigurator::class),
                new Reference(ResultPresenter::class),
                new Reference(BaselinePresenter::class),
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
