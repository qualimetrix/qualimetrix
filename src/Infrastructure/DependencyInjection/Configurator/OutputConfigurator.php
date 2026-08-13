<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Configuration\Contract\Discovery\ComposerAutoloadPathReaderInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfigurationProviderInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyTraversalParticipantInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CollectorRuntimeConfigurationStoreInterface;
use Qualimetrix\Analysis\RuleExecution\RuleExecutorInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryFactoryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DependencyGraphAnalyzerInterface;
use Qualimetrix\Baseline\BaselineCleaner;
use Qualimetrix\Baseline\BaselineGenerator;
use Qualimetrix\Baseline\BaselineLoader;
use Qualimetrix\Baseline\BaselineUpdater;
use Qualimetrix\Baseline\BaselineWriter;
use Qualimetrix\Baseline\BoundaryExplanationService;
use Qualimetrix\Baseline\Suppression\SuppressionFilter;
use Qualimetrix\Configuration\ComputedMetricFormulaValidator;
use Qualimetrix\Configuration\ComputedMetrics\Contract\HealthFormulaExclusionInterface;
use Qualimetrix\Configuration\ComputedMetricsConfigResolver;
use Qualimetrix\Configuration\RuleOptionsFactory;
use Qualimetrix\Configuration\RuleOptionsRegistry;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Core\Coupling\FrameworkNamespacesHolder;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Core\Rule\RuleSelector;
use Qualimetrix\Core\Violation\ChannelDeclarationRegistryInterface;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Console\AnalysisRuntimeConfigurator;
use Qualimetrix\Infrastructure\Console\Command\BaselineCleanupCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineConfiguredThresholds;
use Qualimetrix\Infrastructure\Console\Command\BaselineExplainCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineRun;
use Qualimetrix\Infrastructure\Console\Command\BaselineRunInterface;
use Qualimetrix\Infrastructure\Console\Command\BaselineUpdateCommand;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\Command\GraphExportCommand;
use Qualimetrix\Infrastructure\Console\Command\HookInstallCommand;
use Qualimetrix\Infrastructure\Console\Command\HookStatusCommand;
use Qualimetrix\Infrastructure\Console\Command\HookUninstallCommand;
use Qualimetrix\Infrastructure\Console\Command\RulesCommand;
use Qualimetrix\Infrastructure\Console\DiagnosticOutput;
use Qualimetrix\Infrastructure\Console\ExitCodeResolver;
use Qualimetrix\Infrastructure\Console\FormatterContextFactory;
use Qualimetrix\Infrastructure\Console\MeasuredViolationSet;
use Qualimetrix\Infrastructure\Console\ProfilePresenter;
use Qualimetrix\Infrastructure\Console\Progress\ProgressReporterHolder;
use Qualimetrix\Infrastructure\Console\ResultPresenter;
use Qualimetrix\Infrastructure\Console\RuleInputValidator;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
use Qualimetrix\Infrastructure\Console\ViolationFilterOrchestrator;
use Qualimetrix\Infrastructure\Console\ViolationFilterPipeline;
use Qualimetrix\Infrastructure\Git\GitRepositoryLocator;
use Qualimetrix\Infrastructure\Git\GitRepositoryLocatorInterface;
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
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Configures formatters, baseline services, and CLI commands.
 */
final class OutputConfigurator implements ContainerConfiguratorInterface
{
    private const string DEPENDENCY_GRAPH_ANALYZER = 'qmx.run.dependency_graph_analyzer';
    private const string DEPENDENCY_GRAPH_ANALYZER_CLASS = 'Qualimetrix\\Analysis\\Run\\Pipeline\\DependencyGraphAnalyzer';

    public function __construct(
        private readonly string $srcDir,
    ) {}

    public function configure(ContainerBuilder $container): void
    {
        $this->registerFormatters($container);
        $this->registerGraphProjection($container);
        $this->registerBaseline($container);
        $this->registerCli($container);
    }

    private function registerGraphProjection(ContainerBuilder $container): void
    {
        $projectorServiceId = 'qmx.reporting.graph_projection.projector';
        $container->register($projectorServiceId, 'Qualimetrix\\Reporting\\GraphProjection\\DependencyGraphProjector');
        $container->setAlias('Qualimetrix\\Reporting\\GraphProjection\\Contract\\DependencyGraphProjectionInterface', $projectorServiceId)
            ->setPublic(true);
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
        // Excludes: value objects, enums and exceptions — data, not services.
        // A value object with required constructor arguments cannot be
        // autowired, so leaving one in would fail container compilation
        // rather than merely registering something unused.
        $prototype = (new Definition())->setAutoconfigured(true)->setAutowired(true);
        $loader->registerClasses(
            $prototype,
            'Qualimetrix\\Baseline\\',
            $this->srcDir . '/Baseline/*',
            $this->srcDir . '/Baseline/{'
                . 'Baseline.php,BaselineEdge.php,BaselineEntry.php,BaselineEntryMode.php,'
                . 'BaselineIdentity.php,EntrySelector.php,InertBaselineEntry.php,InertEntryReason.php,'
                . 'BaselineConflictException.php,BaselineEntryRejection.php,'
                . 'BaselineCapture.php,UncapturedGroup.php,UncapturedReason.php,'
                . 'Suppression/Suppression.php}',
        );
    }

    private function registerCli(ContainerBuilder $container): void
    {
        // MeasuredViolationSet — the single definition of the set a baseline
        // measures. The pipeline runs its stages; baseline commands ask it
        // for the set directly.
        $container->register(MeasuredViolationSet::class)
            ->setArguments([
                new Reference(AnalysisPipelineInterface::class),
                new Reference(SuppressionFilter::class),
                new Reference(TransitionalRuntimeConfigurationProviderInterface::class),
            ]);

        // ViolationFilterPipeline
        $container->register(ViolationFilterPipeline::class)
            ->setArguments([
                new Reference(BaselineLoader::class),
                new Reference(ChannelDeclarationRegistryInterface::class),
                new Reference(MeasuredViolationSet::class),
            ]);

        $container->register(AnalysisRuntimeConfigurator::class)
            ->setArguments([
                new Reference(TransitionalRuntimeConfigurationProviderInterface::class),
                new Reference(RuleOptionsRegistry::class),
                new Reference(RuleRegistryInterface::class),
                new Reference(ComputedMetricsConfigResolver::class),
                new Reference(FrameworkNamespacesHolder::class),
                new TaggedIteratorArgument('qmx.analysis.lifecycle_hook'),
                new Reference(CollectorRuntimeConfigurationStoreInterface::class),
            ]);

        // RuntimeConfigurator for runtime service configuration. Public so the
        // deferred-warning integration test can retrieve it from the compiled
        // container and exercise the production drain path end-to-end.
        //
        // AnalysisRuntimeConfigurator receives the tagged feature lifecycle
        // hooks above. RuntimeConfigurator owns only cross-cutting setup and
        // resets the cache before delegating the per-analysis reset.
        $container->register(RuntimeConfigurator::class)
            ->setPublic(true)
            ->setArguments([
                new Reference(LoggerFactory::class),
                new Reference(LoggerHolder::class),
                new Reference(ProgressReporterHolder::class),
                new Reference(ProfilerHolder::class),
                new Reference(AnalysisRuntimeConfigurator::class),
                new Reference(DiagnosticOutput::class),
                new Reference(CacheFactory::class),
            ]);

        // The health implementation remains internal to the Health subdomain;
        // ComputedMetrics consumes its dedicated exclusion contract.
        $healthFormulaExcluder = 'Qualimetrix\\Configuration\\HealthFormulaExcluder';
        $container->register($healthFormulaExcluder);
        $container->setAlias(HealthFormulaExclusionInterface::class, $healthFormulaExcluder);

        // ComputedMetricFormulaValidator (validates expression syntax, references, circular deps)
        $container->register(ComputedMetricFormulaValidator::class);

        // ComputedMetricsConfigResolver
        $container->register(ComputedMetricsConfigResolver::class)
            ->setArguments([
                new Reference(ComputedMetricFormulaValidator::class),
                new Reference(HealthFormulaExclusionInterface::class),
            ]);

        // ProfileSummaryRenderer (stateless, no dependencies)
        $container->register(ProfileSummaryRenderer::class);

        $container->register(DiagnosticOutput::class);
        $container->register(RuleInputValidator::class)
            ->setArguments([
                new Reference(RuleRegistryInterface::class),
                new Reference(RuleSelector::class),
            ]);

        // ProfilePresenter for profiling output
        $container->register(ProfilePresenter::class)
            ->setArguments([
                new Reference(ProfilerHolder::class),
                new Reference(ProfileSummaryRenderer::class),
                new Reference(DiagnosticOutput::class),
            ]);

        // FormatterContextFactory (uses projectRoot for basePath)
        $container->register(FormatterContextFactory::class)
            ->setArguments([
                new Reference(TransitionalRuntimeConfigurationProviderInterface::class),
            ]);

        // ExitCodeResolver for determining process exit code from violations
        $container->register(ExitCodeResolver::class)
            ->setArguments([
                new Reference(TransitionalRuntimeConfigurationProviderInterface::class),
            ]);

        // ViolationFilter for --namespace/--class drill-down
        $container->register(ViolationFilter::class);

        // ResultPresenter for formatting/output of analysis results
        $container->register(ResultPresenter::class)
            ->setArguments([
                new Reference(FormatterRegistryInterface::class),
                new Reference(ProfilerHolder::class),
                new Reference(TransitionalRuntimeConfigurationProviderInterface::class),
                new Reference(SummaryEnricher::class),
                new Reference(ProfilePresenter::class),
                new Reference(ExitCodeResolver::class),
                new Reference(ViolationFilter::class),
                new Reference(FormatterContextFactory::class),
                new Reference(DiagnosticOutput::class),
            ]);

        // ViolationFilterOrchestrator
        $container->register(ViolationFilterOrchestrator::class)
            ->setArguments([
                new Reference(ViolationFilterPipeline::class),
                new Reference(RuleExecutorInterface::class),
                new Reference(DiagnosticOutput::class),
            ]);

        // CheckCommand with all dependencies injected
        $container->register(
            'Qualimetrix\\Infrastructure\\Git\\GitScopeResolver',
            'Qualimetrix\\Infrastructure\\Git\\GitScopeResolver',
        )
            ->setArguments([
                new Reference(FileDiscoveryFactoryInterface::class),
                new Reference(DelegatingLogger::class),
            ]);
        $container->register(
            'Qualimetrix\\Infrastructure\\Console\\ScopeWarningChecker',
            'Qualimetrix\\Infrastructure\\Console\\ScopeWarningChecker',
        )
            ->setArgument('$composerReader', new Reference(ComposerAutoloadPathReaderInterface::class));
        $container->register(
            'Qualimetrix\\Infrastructure\\Console\\CheckScopeResolver',
            'Qualimetrix\\Infrastructure\\Console\\CheckScopeResolver',
        )
            ->setArguments([
                new Reference('Qualimetrix\\Infrastructure\\Git\\GitScopeResolver'),
                new Reference('Qualimetrix\\Infrastructure\\Console\\ScopeWarningChecker'),
            ]);
        $container->register(CheckCommand::class)
            ->setArguments([
                new Reference(RuleRegistryInterface::class),
                new Reference(AnalysisPipelineInterface::class),
                new Reference(CacheFactory::class),
                new Reference(ViolationFilterOrchestrator::class),
                new Reference(ConfigurationPipelineInterface::class),
                new Reference(RuntimeConfigurator::class),
                new Reference(ResultPresenter::class),
                new Reference(RuleInputValidator::class),
                new Reference(DiagnosticOutput::class),
                new Reference('Qualimetrix\\Infrastructure\\Console\\CheckScopeResolver'),
            ])
            ->setPublic(true);

        $this->registerBaselineCommands($container);

        // GitRepositoryLocator (shared by hook commands)
        $container->register(GitRepositoryLocator::class);
        $container->setAlias(GitRepositoryLocatorInterface::class, GitRepositoryLocator::class);

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
                [], // Will be set by RuleCompilerPass
            ])
            ->setPublic(true);

        $container->register(self::DEPENDENCY_GRAPH_ANALYZER, self::DEPENDENCY_GRAPH_ANALYZER_CLASS)
            ->setArguments([
                new Reference(FileDiscoveryInterface::class),
                new Reference(FileParserInterface::class),
                new Reference(DependencyTraversalParticipantInterface::class),
                new Reference(DependencyGraphBuilderInterface::class),
            ]);
        $container->setAlias(DependencyGraphAnalyzerInterface::class, self::DEPENDENCY_GRAPH_ANALYZER);

        // GraphExportCommand
        $container->register(GraphExportCommand::class)
            ->setArguments([
                new Reference(DependencyGraphAnalyzerInterface::class),
                new Reference('Qualimetrix\\Reporting\\GraphProjection\\Contract\\DependencyGraphProjectionInterface'),
                new Reference(DelegatingLogger::class),
            ])
            ->setPublic(true);
    }

    /**
     * The five commands of the baseline lifecycle, plus the run they all
     * measure against.
     *
     * `BaselineRun` is registered once and injected into every one of them:
     * that is what makes "one measured set" a property of the wiring rather
     * than a rule each command has to remember.
     */
    private function registerBaselineCommands(ContainerBuilder $container): void
    {
        $container->register(BaselineRun::class)
            ->setArguments([
                new Reference(ConfigurationPipelineInterface::class),
                new Reference(RuntimeConfigurator::class),
                new Reference(MeasuredViolationSet::class),
                new Reference(TransitionalRuntimeConfigurationProviderInterface::class),
                new Reference(RuleInputValidator::class),
                new Reference(FileDiscoveryFactoryInterface::class),
            ]);

        $container->setAlias(BaselineRunInterface::class, BaselineRun::class);

        $container->register(BaselineConfiguredThresholds::class)
            ->setArguments([
                new Reference(RuleRegistryInterface::class),
                new Reference(RuleOptionsFactory::class),
            ]);

        $container->register(BaselineGenerateCommand::class)
            ->setArguments([
                new Reference(BaselineRun::class),
                new Reference(BaselineGenerator::class),
                new Reference(BaselineWriter::class),
            ])
            ->setPublic(true);

        $container->register(BaselineUpdateCommand::class)
            ->setArguments([
                new Reference(BaselineRun::class),
                new Reference(BaselineLoader::class),
                new Reference(BaselineUpdater::class),
                new Reference(BaselineWriter::class),
            ])
            ->setPublic(true);

        $container->register(BaselineCleanupCommand::class)
            ->setArguments([
                new Reference(BaselineRun::class),
                new Reference(BaselineLoader::class),
                new Reference(BaselineCleaner::class),
                new Reference(BaselineWriter::class),
                new Reference(ChannelDeclarationRegistryInterface::class),
            ])
            ->setPublic(true);

        $container->register(BaselineExplainCommand::class)
            ->setArguments([
                new Reference(BaselineRun::class),
                new Reference(BaselineLoader::class),
                new Reference(BoundaryExplanationService::class),
                new Reference(BaselineConfiguredThresholds::class),
            ])
            ->setPublic(true);
    }
}
