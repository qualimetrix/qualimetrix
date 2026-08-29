<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Configuration\Contract\Discovery\ComposerAutoloadPathReaderInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfigurationResolverInterface;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfigurationStoreInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Evidence\Coupling\Contract\Configuration\CouplingConfiguratorInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyTraversalParticipantInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Finding\Configuration\FindingConfigurationResolver;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfigurationResolverInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePolicyConfiguratorInterface;
use Qualimetrix\Analysis\Policy\Baseline\BaselineCleaner;
use Qualimetrix\Analysis\Policy\Baseline\BaselineGenerator;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\BaselineUpdater;
use Qualimetrix\Analysis\Policy\Baseline\BaselineWriter;
use Qualimetrix\Analysis\Policy\Baseline\BoundaryExplanationService;
use Qualimetrix\Analysis\Policy\Inline\Contract\AnnotationSuppressionInterface;
use Qualimetrix\Analysis\Run\Configuration\RunConfigurationResolver;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfigurationResolverInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryFactoryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DependencyGraphAnalyzerInterface;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationResolverInterface;
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
use Qualimetrix\Infrastructure\Console\ConfigurationInputAdapter;
use Qualimetrix\Infrastructure\Console\DiagnosticOutput;
use Qualimetrix\Infrastructure\Console\ExitCodeResolver;
use Qualimetrix\Infrastructure\Console\FindingFilterOrchestrator;
use Qualimetrix\Infrastructure\Console\FormatterContextFactory;
use Qualimetrix\Infrastructure\Console\MeasuredFindingSet;
use Qualimetrix\Infrastructure\Console\ProfilePresenter;
use Qualimetrix\Infrastructure\Console\ProfileSummaryRenderer;
use Qualimetrix\Infrastructure\Console\Progress\SwitchableProgressReporter;
use Qualimetrix\Infrastructure\Console\ResultPresenter;
use Qualimetrix\Infrastructure\Console\RuleInputValidator;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
use Qualimetrix\Infrastructure\Console\RuntimeLimitsController;
use Qualimetrix\Infrastructure\Git\GitRepositoryLocator;
use Qualimetrix\Infrastructure\Git\GitRepositoryLocatorInterface;
use Qualimetrix\Infrastructure\Logging\Contract\LoggerFactoryInterface;
use Qualimetrix\Infrastructure\Logging\DelegatingLogger;
use Qualimetrix\Infrastructure\Logging\LoggerHolder;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationResolverInterface;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationStoreInterface;
use Qualimetrix\Infrastructure\Profiler\Contract\ProfileReportInterface;
use Qualimetrix\Infrastructure\Profiler\Contract\ProfileSessionControlInterface;
use Qualimetrix\Infrastructure\Rule\Contract\RuleChannelSnapshotFactoryInterface;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Qualimetrix\Reporting\Configuration\OutputFormatResolver;
use Qualimetrix\Reporting\Contract\OutputFormatResolverInterface;
use Qualimetrix\Reporting\Filter\FindingFilter;
use Qualimetrix\Reporting\FindingProjection\Configuration\ConfiguredFindingExclusionsResolver;
use Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusionsResolverInterface;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeQueryInterface;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use Qualimetrix\Reporting\Health\SummaryEnricher;
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
        $detailedFindingRenderer = 'Qualimetrix\\Reporting\\Formatter\\Support\\DetailedFindingRenderer';
        $formatterRegistry = 'Qualimetrix\\Reporting\\Formatter\\FormatterRegistry';
        $loader = new PhpFileLoader($container, new FileLocator($this->srcDir));

        // Register Prioritization evidence consumed by Reporting.
        $prioritizationPrototype = (new Definition())->setAutoconfigured(true)->setAutowired(true);
        $loader->registerClasses(
            $prioritizationPrototype,
            'Qualimetrix\\Analysis\\Evidence\\Prioritization\\',
            $this->srcDir . '/Analysis/Evidence/Prioritization/{Debt,Impact}/*',
            $this->srcDir . '/Analysis/Evidence/Prioritization/Impact/RankedIssue.php',
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

        // FindingFilter (shared filtering logic for formatters)
        $container->register(FindingFilter::class);

        // DetailedFindingRenderer (in Formatter/Support/, excluded from formatter glob)
        $container->register($detailedFindingRenderer)
            ->setAutowired(true);

        // FormatterRegistry will be populated by compiler pass
        $container->register($formatterRegistry)
            ->setArguments([[]]);

        $container->setAlias(FormatterRegistryInterface::class, $formatterRegistry)
            ->setPublic(true);
    }

    private function registerBaseline(ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator($this->srcDir));

        // Auto-register all baseline services from src/Analysis/Policy/Baseline/*
        // Excludes: value objects, enums and exceptions — data, not services.
        // A value object with required constructor arguments cannot be
        // autowired, so leaving one in would fail container compilation
        // rather than merely registering something unused.
        $prototype = (new Definition())->setAutoconfigured(true)->setAutowired(true);
        $loader->registerClasses(
            $prototype,
            'Qualimetrix\\Analysis\\Policy\\Baseline\\',
            $this->srcDir . '/Analysis/Policy/Baseline/*',
            $this->srcDir . '/Analysis/Policy/Baseline/{'
                . 'Baseline.php,BaselineEdge.php,BaselineEntry.php,BaselineEntryMode.php,'
                . 'BaselineIdentity.php,EntrySelector.php,InertBaselineEntry.php,InertEntryReason.php,'
                . 'BaselineConflictException.php,BaselineEntryRejection.php,'
                . 'BaselineCapture.php,UncapturedGroup.php,UncapturedReason.php}',
        );
    }

    private function registerCli(ContainerBuilder $container): void
    {
        $runtimeLoggerConfigurator = 'Qualimetrix\\Infrastructure\\Console\\RuntimeLoggerConfigurator';
        $suppressionFilter = 'Qualimetrix\\Analysis\\Policy\\Inline\\Suppression\\SuppressionFilter';
        $gitScopeQuery = 'Qualimetrix\\Infrastructure\\Git\\ReportingGitScopeQuery';
        $findingProjector = 'Qualimetrix\\Reporting\\FindingProjection\\FindingProjector';

        $container->register(FindingConfigurationResolver::class);
        $container->setAlias(FindingConfigurationResolverInterface::class, FindingConfigurationResolver::class);
        $container->register(ConfigurationInputAdapter::class)
            ->setArguments([
                new Reference(ConfigurationPipelineInterface::class),
            ]);
        $container->register(RunConfigurationResolver::class);
        $container->setAlias(RunConfigurationResolverInterface::class, RunConfigurationResolver::class);
        $container->register(OutputFormatResolver::class);
        $container->setAlias(OutputFormatResolverInterface::class, OutputFormatResolver::class);
        $container->register(ConfiguredFindingExclusionsResolver::class);
        $container->setAlias(
            ConfiguredFindingExclusionsResolverInterface::class,
            ConfiguredFindingExclusionsResolver::class,
        );

        $container->register($suppressionFilter);
        $container->setAlias(AnnotationSuppressionInterface::class, $suppressionFilter)
            ->setPublic(true);

        $container->register($gitScopeQuery);
        $container->setAlias(GitScopeQueryInterface::class, $gitScopeQuery)
            ->setPublic(true);

        $container->register($findingProjector)
            ->setArguments([
                new Reference(AnnotationSuppressionInterface::class),
                new Reference(BaselineLoader::class),
                new Reference(ChannelDeclarationRegistryInterface::class),
                new Reference(GitScopeQueryInterface::class),
            ]);

        // MeasuredFindingSet — the single definition of the set a baseline
        // measures. The pipeline runs its stages; baseline commands ask it
        // for the set directly.
        $container->register(MeasuredFindingSet::class)
            ->setArguments([
                new Reference(AnalysisPipelineInterface::class),
                new Reference($findingProjector),
                new Reference(FileDiscoveryFactoryInterface::class),
            ]);

        $container->register(AnalysisRuntimeConfigurator::class)
            ->setArguments([
                new Reference(RuleConfigurationInterface::class),
                new Reference(LcomCollectionConfigurationResolverInterface::class),
                new Reference(LcomCollectionConfigurationStoreInterface::class),
                new Reference(ArchitecturePolicyConfiguratorInterface::class),
                new Reference(ComputedMetricConfiguratorInterface::class),
                new Reference(CouplingConfiguratorInterface::class),
                new Reference(RuleInputValidator::class),
            ]);

        $container->register($runtimeLoggerConfigurator, $runtimeLoggerConfigurator)
            ->setArguments([
                new Reference(LoggerFactoryInterface::class),
                new Reference(LoggerHolder::class),
            ]);

        // RuntimeConfigurator owns cross-cutting setup and resets owner-local
        // runtime state before each configuration resolution.
        $container->register(RuntimeConfigurator::class)
            ->setPublic(true)
            ->setArguments([
                new Reference($runtimeLoggerConfigurator),
                new Reference(SwitchableProgressReporter::class),
                new Reference(ProfileSessionControlInterface::class),
                new Reference(AnalysisRuntimeConfigurator::class),
                new Reference(CacheFactory::class),
                new Reference(ParallelConfigurationStoreInterface::class),
                new Reference(RuntimeLimitsController::class),
            ]);

        // ProfileSummaryRenderer (stateless, no dependencies)
        $container->register(ProfileSummaryRenderer::class);

        $container->register(DiagnosticOutput::class);
        $container->register(RuntimeLimitsController::class);
        $container->register(RuleInputValidator::class)
            ->setArguments([
                new Reference(RuleRegistryInterface::class),
                new Reference(RuleSelector::class),
                new Reference(FindingConfigurationResolverInterface::class),
                new Reference(RuleChannelSnapshotFactoryInterface::class),
            ]);

        // ProfilePresenter for profiling output
        $container->register(ProfilePresenter::class)
            ->setArguments([
                new Reference(ProfileReportInterface::class),
                new Reference(ProfileSummaryRenderer::class),
                new Reference(DiagnosticOutput::class),
            ]);

        $container->register(FormatterContextFactory::class);

        $container->register(ExitCodeResolver::class)
            ->setArguments([new Reference(ChannelDeclarationRegistryInterface::class)]);

        // FindingFilter for --namespace/--class drill-down
        $container->register(FindingFilter::class);

        // ResultPresenter for formatting/output of analysis results
        $container->register(ResultPresenter::class)
            ->setArguments([
                new Reference(FormatterRegistryInterface::class),
                new Reference(ProfilerInterface::class),
                new Reference(SummaryEnricher::class),
                new Reference(ProfilePresenter::class),
                new Reference(ExitCodeResolver::class),
                new Reference(FindingFilter::class),
                new Reference(FormatterContextFactory::class),
                new Reference(RuleConfigurationInterface::class),
            ]);

        // FindingFilterOrchestrator
        $container->register(FindingFilterOrchestrator::class)
            ->setArguments([
                new Reference($findingProjector),
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
                new Reference(AnalysisPipelineInterface::class),
                new Reference(FindingFilterOrchestrator::class),
                new Reference(RuntimeConfigurator::class),
                new Reference(ResultPresenter::class),
                new Reference(RuleInputValidator::class),
                new Reference('Qualimetrix\\Infrastructure\\Console\\CheckScopeResolver'),
                new Reference(ConfigurationInputAdapter::class),
                new Reference(RunConfigurationResolverInterface::class),
                new Reference(CacheConfigurationResolverInterface::class),
                new Reference(ParallelConfigurationResolverInterface::class),
                new Reference(ConfiguredFindingExclusionsResolverInterface::class),
                new Reference(OutputFormatResolverInterface::class),
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
                new Reference(RuleExecutionInterface::class),
            ])
            ->setPublic(true);

        $container->register(self::DEPENDENCY_GRAPH_ANALYZER, self::DEPENDENCY_GRAPH_ANALYZER_CLASS)
            ->setArguments([
                new Reference(FileDiscoveryInterface::class),
                new Reference(FileParserInterface::class),
                new Reference(DependencyTraversalParticipantInterface::class),
                new Reference(DependencyGraphBuilderInterface::class),
                new Reference(DeclarationRegistrarFactory::class),
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
                new Reference(RuntimeConfigurator::class),
                new Reference(MeasuredFindingSet::class),
                new Reference(RuleInputValidator::class),
                new Reference(ConfigurationInputAdapter::class),
                new Reference(RunConfigurationResolverInterface::class),
                new Reference(ConfiguredFindingExclusionsResolverInterface::class),
                new Reference(CacheConfigurationResolverInterface::class),
                new Reference(ParallelConfigurationResolverInterface::class),
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
