<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyTraversalParticipantInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Finding\Configuration\FindingConfigurationResolver;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfigurationResolverInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory;
use Qualimetrix\Analysis\Policy\Baseline\BaselineChannelRenamer;
use Qualimetrix\Analysis\Policy\Baseline\BaselineCleaner;
use Qualimetrix\Analysis\Policy\Baseline\BaselineGenerator;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\BaselineUpdater;
use Qualimetrix\Analysis\Policy\Baseline\BaselineWriter;
use Qualimetrix\Analysis\Policy\Baseline\BoundaryExplanationService;
use Qualimetrix\Analysis\Policy\Baseline\RunRuleCoverage;
use Qualimetrix\Analysis\Policy\Inline\Contract\AnnotationSuppressionInterface;
use Qualimetrix\Analysis\Run\Configuration\PathsSection;
use Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage;
use Qualimetrix\Analysis\Run\Configuration\RunConfigurationResolver;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfigurationResolverInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryFactoryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\GeneratedFileFilterInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DependencyGraphAnalyzerInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DirectiveAuditInterface;
use Qualimetrix\Analysis\Run\Discovery\AnalysisFileDiscovery;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeAudit;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Infrastructure\Console\AnalysisInputPathValidator;
use Qualimetrix\Infrastructure\Console\AnalysisPreflight;
use Qualimetrix\Infrastructure\Console\AnalysisRuntimeConfigurator;
use Qualimetrix\Infrastructure\Console\CheckConfigurationResolvers;
use Qualimetrix\Infrastructure\Console\Command\BaselineCleanupCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineConfiguredThresholds;
use Qualimetrix\Infrastructure\Console\Command\BaselineExplainCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineRenameChannelsCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineRun;
use Qualimetrix\Infrastructure\Console\Command\BaselineRunInterface;
use Qualimetrix\Infrastructure\Console\Command\BaselineUpdateCommand;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\Command\DirectivesCommand;
use Qualimetrix\Infrastructure\Console\Command\GraphExportCommand;
use Qualimetrix\Infrastructure\Console\Command\HookInstallCommand;
use Qualimetrix\Infrastructure\Console\Command\HookStatusCommand;
use Qualimetrix\Infrastructure\Console\Command\HookUninstallCommand;
use Qualimetrix\Infrastructure\Console\Command\RulesCommand;
use Qualimetrix\Infrastructure\Console\ConfigurationInputAdapter;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\ExitCodeResolver;
use Qualimetrix\Infrastructure\Console\ExitPolicySection;
use Qualimetrix\Infrastructure\Console\FindingFilterOrchestrator;
use Qualimetrix\Infrastructure\Console\FormatterContextFactory;
use Qualimetrix\Infrastructure\Console\MeasuredFindingSet;
use Qualimetrix\Infrastructure\Console\MemoryLimitSection;
use Qualimetrix\Infrastructure\Console\ObservedProjectScopeReasons;
use Qualimetrix\Infrastructure\Console\ProfilePresenter;
use Qualimetrix\Infrastructure\Console\ProfileSummaryRenderer;
use Qualimetrix\Infrastructure\Console\Progress\ProgressConfigurator;
use Qualimetrix\Infrastructure\Console\ProjectSourceConfigurator;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\Console\ResultPresenter;
use Qualimetrix\Infrastructure\Console\RuleInputValidator;
use Qualimetrix\Infrastructure\Console\RuleListingPresenter;
use Qualimetrix\Infrastructure\Console\RunConfigurationPreparation;
use Qualimetrix\Infrastructure\Console\RunningBinaryLocator;
use Qualimetrix\Infrastructure\Console\RunningBinaryLocatorInterface;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
use Qualimetrix\Infrastructure\Console\RuntimeLimitsController;
use Qualimetrix\Infrastructure\Console\RuntimeLoggerConfigurator;
use Qualimetrix\Infrastructure\Git\GitRepositoryLocator;
use Qualimetrix\Infrastructure\Git\GitRepositoryLocatorInterface;
use Qualimetrix\Infrastructure\Logging\DelegatingLogger;
use Qualimetrix\Infrastructure\Logging\LoggerHolder;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Qualimetrix\Reporting\Configuration\OutputFormatResolver;
use Qualimetrix\Reporting\Configuration\OutputFormatSection;
use Qualimetrix\Reporting\Configuration\OutputFormatVocabulary;
use Qualimetrix\Reporting\Contract\OutputFormatResolverInterface;
use Qualimetrix\Reporting\DrillDown\FindingFilter;
use Qualimetrix\Reporting\FindingProjection\Configuration\ConfiguredFindingExclusionsResolver;
use Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusionsResolverInterface;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeQueryInterface;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
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
        //
        // The exclusion criterion, so that it is applied rather than argued each
        // time: a class is excluded only when the container cannot build it, or
        // when it is built by hand below. `Ansi/` is the first — `AnsiColor`
        // takes a bool nothing can supply — and `FormatterRegistry` is the
        // second. A static-only helper is neither: registering one costs an
        // unused private definition that is removed before compilation, which
        // is why the narrators and the sorters are simply left alone.
        //
        // A stale path in this exclude is silent: the extra services compile,
        // autowiring failures are deferred to instantiation, and unused private
        // services are removed before one can surface.
        // RegisterClassesExcludesNameSomethingTest is what refuses one.
        $prototype = (new Definition())->setAutoconfigured(true)->setAutowired(true);
        $loader->registerClasses(
            $prototype,
            'Qualimetrix\\Reporting\\Formatter\\',
            $this->srcDir . '/Reporting/Formatter/{*,**/*}',
            $this->srcDir . '/Reporting/Formatter/{FormatterRegistry.php,Ansi/**}',
        );

        // Auto-register health scoring services from src/Reporting/Health/
        // No exclude: every class here is an autowirable service.
        $healthPrototype = (new Definition())->setAutoconfigured(true)->setAutowired(true);
        $loader->registerClasses(
            $healthPrototype,
            'Qualimetrix\\Reporting\\Health\\',
            $this->srcDir . '/Reporting/Health/*',
        );

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
        // Excludes: value objects, enums, exceptions and pure static helpers —
        // data and functions, not services.
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
                . 'BaselineCapture.php,UncapturedGroup.php,UncapturedReason.php,'
                . 'BaselineDocumentLayout.php,BaselineEntryOrder.php,BaselineEntryPayload.php,'
                . 'BaselineFormatVersion.php,'
                . 'ChannelRenameMap.php,ChannelRenameReport.php,ChannelRenameRefusal.php,'
                . 'ExplainedSubject.php}',
        );
    }

    private function registerCli(ContainerBuilder $container): void
    {
        $this->registerRunInputs($container);
        $this->registerFindingProjection($container);
        $this->registerRuntimePreparation($container);
        $this->registerCheckDelivery($container);
        $this->registerHookDelivery($container);
        $this->registerAuditAndGraphDelivery($container);
        $this->registerBaselineCommands($container);
    }

    private function registerRunInputs(ContainerBuilder $container): void
    {
        $container->register(FindingConfigurationResolver::class);
        $container->setAlias(FindingConfigurationResolverInterface::class, FindingConfigurationResolver::class);
        $container->register(ConfigurationInputAdapter::class)
            ->setAutowired(true);
        $container->register(ExitPolicySection::class)->setAutoconfigured(true);
        $container->register(MemoryLimitSection::class)->setAutoconfigured(true);
        $container->register(PathsSection::class)->setAutoconfigured(true);
        $container->register(RunConfigurationResolver::class)
            ->setArgument('$projectScopeCoverage', new Reference(ProjectScopeCoverage::class));
        $container->setAlias(RunConfigurationResolverInterface::class, RunConfigurationResolver::class);
        $container->register(OutputFormatVocabulary::class)
            ->setAutowired(true);
        $container->register(OutputFormatSection::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);
        $container->register(OutputFormatResolver::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);
        $container->setAlias(OutputFormatResolverInterface::class, OutputFormatResolver::class);
        $container->register(ConfiguredFindingExclusionsResolver::class);
        $container->setAlias(
            ConfiguredFindingExclusionsResolverInterface::class,
            ConfiguredFindingExclusionsResolver::class,
        );
    }

    private function registerFindingProjection(ContainerBuilder $container): void
    {
        $suppressionFilter = 'Qualimetrix\\Analysis\\Policy\\Inline\\Suppression\\SuppressionFilter';
        $gitScopeQuery = 'Qualimetrix\\Infrastructure\\Git\\ReportingGitScopeQuery';
        $findingProjector = 'Qualimetrix\\Reporting\\FindingProjection\\FindingProjector';

        $container->register($suppressionFilter);
        $container->setAlias(AnnotationSuppressionInterface::class, $suppressionFilter)
            ->setPublic(true);

        // Named, not autowired: this is where `GitClient::getChangedFiles()`
        // actually runs, and its warnings about changed files it had to drop
        // reach a reader only through this argument.
        $container->register($gitScopeQuery)
            ->setArgument('$logger', new Reference(DelegatingLogger::class));
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
            ->setAutowired(true);
    }

    private function registerRuntimePreparation(ContainerBuilder $container): void
    {
        $container->register(RunConfigurationPreparation::class)->setAutowired(true);
        $container->register(ObservedProjectScopeReasons::class)->setAutowired(true);

        $container->register(AnalysisRuntimeConfigurator::class)
            ->setAutowired(true);

        $container->register(RuntimeLoggerConfigurator::class)
            ->setAutowired(true)
            ->setArgument('$loggerHolder', new Reference(LoggerHolder::class));

        $container->register(ProjectSourceConfigurator::class)
            ->setAutowired(true);

        // RuntimeConfigurator owns cross-cutting setup and resets owner-local
        // runtime state before each configuration resolution.
        $container->register(RuntimeConfigurator::class)
            ->setAutowired(true)
            ->setPublic(true);

        // ProfileSummaryRenderer (stateless, no dependencies)
        $container->register(ProfileSummaryRenderer::class);

        // ErrorStream is the single owner of the run's error stream: the
        // progress section and every diagnostic writer come from this one
        // shared instance, which is what makes them redraw around each other.
        $container->register(ErrorStream::class)->setPublic(true);

        // RefusalPresenter: the single writer for a configuration refusal or
        // an internal error, shared by every command whose exit-code ladder
        // catches ConfigurationRefusal — `check`, the five `baseline:*`, and
        // `directives` here; `debug:layer-assignment` gets its own reference
        // in ArchitectureConfigurator. Public, like ErrorStream above: `bin/qmx`
        // fetches this exact instance to hand `Application`; a second,
        // container-invisible instance would create a second diagnostic dialect.
        $container->register(RefusalPresenter::class)
            ->setAutowired(true)
            ->setPublic(true);

        $container->register(ProgressConfigurator::class)
            ->setAutowired(true);
        $container->register(RuntimeLimitsController::class);
        $container->register(RuleInputValidator::class)
            ->setAutowired(true);

        // ProfilePresenter for profiling output
        $container->register(ProfilePresenter::class)
            ->setAutowired(true);

        $container->register(FormatterContextFactory::class)
            ->setAutowired(true);

        $container->register(ExitCodeResolver::class)
            ->setAutowired(true);

        // What --namespace/--class selects; ResultPresenter and the autowired
        // formatter sections both resolve it from here.
        $container->register(FindingFilter::class);

        // ResultPresenter for formatting/output of analysis results
        $container->register(ResultPresenter::class)
            ->setAutowired(true);

        // FindingFilterOrchestrator
        $container->register(FindingFilterOrchestrator::class)
            ->setAutowired(true);
    }

    private function registerCheckDelivery(ContainerBuilder $container): void
    {
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
        );
        $container->register(
            'Qualimetrix\\Infrastructure\\Console\\CheckScopeResolver',
            'Qualimetrix\\Infrastructure\\Console\\CheckScopeResolver',
        )
            ->setArguments([
                new Reference('Qualimetrix\\Infrastructure\\Git\\GitScopeResolver'),
                new Reference('Qualimetrix\\Infrastructure\\Console\\ScopeWarningChecker'),
            ]);
        $container->register(CheckConfigurationResolvers::class)
            ->setAutowired(true);

        $container->register(CheckCommand::class)
            ->setArguments([
                new Reference(AnalysisPipelineInterface::class),
                new Reference(FindingFilterOrchestrator::class),
                new Reference(RuntimeConfigurator::class),
                new Reference(ResultPresenter::class),
                new Reference(RuleInputValidator::class),
                new Reference('Qualimetrix\\Infrastructure\\Console\\CheckScopeResolver'),
                new Reference(ConfigurationInputAdapter::class),
                new Reference(CheckConfigurationResolvers::class),
                new Reference(RefusalPresenter::class),
            ])
            ->setPublic(true);
    }

    private function registerHookDelivery(ContainerBuilder $container): void
    {
        // GitRepositoryLocator (shared by hook commands)
        $container->register(GitRepositoryLocator::class);
        $container->setAlias(GitRepositoryLocatorInterface::class, GitRepositoryLocator::class);

        // RunningBinaryLocator (the path hook:install bakes into the hook)
        $container->register(RunningBinaryLocator::class);
        $container->setAlias(RunningBinaryLocatorInterface::class, RunningBinaryLocator::class);

        // HookInstallCommand
        $container->register(HookInstallCommand::class)
            ->setArguments([
                new Reference(GitRepositoryLocator::class),
                new Reference(RunningBinaryLocator::class),
            ])
            ->setPublic(true);

        // HookUninstallCommand
        $container->register(HookUninstallCommand::class)
            ->setArguments([
                new Reference(GitRepositoryLocator::class),
                new Reference(RunningBinaryLocator::class),
            ])
            ->setPublic(true);

        // HookStatusCommand
        $container->register(HookStatusCommand::class)
            ->setArguments([
                new Reference(GitRepositoryLocator::class),
                new Reference(RunningBinaryLocator::class),
            ])
            ->setPublic(true);
    }

    private function registerAuditAndGraphDelivery(ContainerBuilder $container): void
    {
        // DirectivesCommand. It reads the pipeline's *second* contract — the
        // audit — and never AnalysisPipelineInterface: analysing and auditing
        // are two questions, and the four consumers of the first do not ask
        // the second.
        $container->register(AnalysisInputPathValidator::class);
        $container->register(AnalysisPreflight::class)
            ->setAutowired(true);
        $container->register(DirectivesCommand::class)
            ->setArguments([
                new Reference(DirectiveAuditInterface::class),
                new Reference(AnalysisPreflight::class),
                new Reference(RefusalPresenter::class),
            ])
            ->setPublic(true);

        // RulesCommand
        $container->register(RuleListingPresenter::class);

        $container->register(RulesCommand::class)
            ->setArguments([
                new Reference(RuleExecutionInterface::class),
                new Reference(RuleChannelRegistryInterface::class),
                new Reference(ChannelDeclarationRegistryInterface::class),
                new Reference(RuleListingPresenter::class),
                new Reference(ConfigurationInputAdapter::class),
                new Reference(FindingConfigurationResolverInterface::class),
                new Reference(ComputedMetricConfiguratorInterface::class),
            ])
            ->setPublic(true);

        $container->register(AnalysisFileDiscovery::class)
            ->setArguments([
                new Reference(FileDiscoveryInterface::class),
                new Reference(GeneratedFileFilterInterface::class),
                new Reference(UnmatchedExcludeAudit::class),
            ]);
        $container->register(self::DEPENDENCY_GRAPH_ANALYZER, self::DEPENDENCY_GRAPH_ANALYZER_CLASS)
            ->setArguments([
                new Reference(AnalysisFileDiscovery::class),
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
                new Reference(AnalysisPreflight::class),
                new Reference(ErrorStream::class),
                new Reference(RefusalPresenter::class),
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
            ->setAutowired(true);

        $container->setAlias(BaselineRunInterface::class, BaselineRun::class);

        $container->register(BaselineConfiguredThresholds::class)
            ->setArguments([
                new Reference(RuleRegistryInterface::class),
                new Reference(RuleOptionsFactory::class),
            ]);

        // Every one of the five gets RefusalPresenter through a method call
        // rather than a constructor argument: BaselineCommand's shared ladder
        // declares the setter precisely so this doesn't mean touching five
        // different constructors. Five separate calls, named individually
        // below rather than in a loop, so a missed registration shows up as
        // one command short an argument rather than dropped silently from an
        // iterable.
        $refusalPresenterCall = ['setRefusalPresenter', [new Reference(RefusalPresenter::class)]];

        $container->register(BaselineGenerateCommand::class)
            ->setArguments([
                new Reference(BaselineRun::class),
                new Reference(BaselineGenerator::class),
                new Reference(BaselineWriter::class),
            ])
            ->addMethodCall(...$refusalPresenterCall)
            ->setPublic(true);

        $container->register(BaselineUpdateCommand::class)
            ->setArguments([
                new Reference(BaselineRun::class),
                new Reference(BaselineLoader::class),
                new Reference(BaselineUpdater::class),
                new Reference(BaselineWriter::class),
            ])
            ->addMethodCall(...$refusalPresenterCall)
            ->setPublic(true);

        $container->register(BaselineCleanupCommand::class)
            ->setArguments([
                new Reference(BaselineRun::class),
                new Reference(BaselineLoader::class),
                new Reference(BaselineCleaner::class),
                new Reference(BaselineWriter::class),
                new Reference(ChannelDeclarationRegistryInterface::class),
                new Reference(RunRuleCoverage::class),
            ])
            ->addMethodCall(...$refusalPresenterCall)
            ->setPublic(true);

        // The one baseline command with no measured run: a carry substitutes a
        // declared name in a file and consults no analysis, so it takes the
        // renamer and nothing else.
        $container->register(BaselineRenameChannelsCommand::class)
            ->setArguments([
                new Reference(BaselineChannelRenamer::class),
            ])
            ->addMethodCall(...$refusalPresenterCall)
            ->setPublic(true);

        $container->register(BaselineExplainCommand::class)
            ->setArguments([
                new Reference(BaselineRun::class),
                new Reference(BaselineLoader::class),
                new Reference(BoundaryExplanationService::class),
                new Reference(BaselineConfiguredThresholds::class),
                new Reference(ChannelDeclarationRegistryInterface::class),
            ])
            ->addMethodCall(...$refusalPresenterCall)
            ->setPublic(true);
    }
}
