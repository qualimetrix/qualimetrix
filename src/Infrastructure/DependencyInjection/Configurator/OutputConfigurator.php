<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyTraversalParticipantInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Filter\ChannelFileScope;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\Selection\RuleEnablementResolver;
use Qualimetrix\Analysis\Policy\Baseline\Capture\BaselineGenerator;
use Qualimetrix\Analysis\Policy\Baseline\ChannelRename\BaselineChannelRenamer;
use Qualimetrix\Analysis\Policy\Baseline\DocumentAccess\BaselineDocumentReader;
use Qualimetrix\Analysis\Policy\Baseline\DocumentReading\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\DocumentWriting\BaselineWriter;
use Qualimetrix\Analysis\Policy\Baseline\EntryBinding\UnusedEntryAudit;
use Qualimetrix\Analysis\Policy\Baseline\EntryMaintenance\BaselineCleaner;
use Qualimetrix\Analysis\Policy\Baseline\EntryMaintenance\BaselineUpdater;
use Qualimetrix\Analysis\Policy\Baseline\Explanation\BoundaryExplanationService;
use Qualimetrix\Analysis\Policy\Baseline\RunCoverage\RunRuleCoverage;
use Qualimetrix\Analysis\Policy\Inline\Contract\AnnotationSuppressionInterface;
use Qualimetrix\Analysis\Run\Configuration\PathsSection;
use Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage;
use Qualimetrix\Analysis\Run\Configuration\RunConfigurationResolver;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfigurationResolverInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectFilesInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DependencyGraphAnalyzerInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DirectiveAuditInterface;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Infrastructure\Console\Application\RunningBinaryLocator;
use Qualimetrix\Infrastructure\Console\Application\RunningBinaryLocatorInterface;
use Qualimetrix\Infrastructure\Console\BaselineCommand\BaselineProjectionCoverage;
use Qualimetrix\Infrastructure\Console\BaselineCommand\MeasuredFindingSet;
use Qualimetrix\Infrastructure\Console\CacheLifecycle\CacheRunLifecycle;
use Qualimetrix\Infrastructure\Console\Check\CheckCommand;
use Qualimetrix\Infrastructure\Console\CheckReport\CheckFindingProjection;
use Qualimetrix\Infrastructure\Console\CheckReport\CheckReportDelivery;
use Qualimetrix\Infrastructure\Console\CheckReport\CheckReportRenderer;
use Qualimetrix\Infrastructure\Console\CheckReport\FindingDiagnostics;
use Qualimetrix\Infrastructure\Console\CheckReport\FindingProjection;
use Qualimetrix\Infrastructure\Console\CheckReport\FormatterContextFactory;
use Qualimetrix\Infrastructure\Console\CheckReport\ReportAssembler;
use Qualimetrix\Infrastructure\Console\CheckReport\ReportFormatter;
use Qualimetrix\Infrastructure\Console\CheckScope\FindingProjectionOptionsResolver;
use Qualimetrix\Infrastructure\Console\CheckScope\ObservedProjectScopeReasons;
use Qualimetrix\Infrastructure\Console\CheckScope\ProjectScopePresenter;
use Qualimetrix\Infrastructure\Console\Command\Baseline\BaselineRunInterface;
use Qualimetrix\Infrastructure\Console\Command\Baseline\ChannelRename\BaselineRenameChannelsCommand;
use Qualimetrix\Infrastructure\Console\Command\Baseline\Explanation\BaselineConfiguredThresholds;
use Qualimetrix\Infrastructure\Console\Command\Baseline\Explanation\BaselineExplainCommand;
use Qualimetrix\Infrastructure\Console\Command\Baseline\Generation\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Command\Baseline\Maintenance\BaselineCleanupCommand;
use Qualimetrix\Infrastructure\Console\Command\Baseline\Maintenance\BaselineUpdateCommand;
use Qualimetrix\Infrastructure\Console\Command\Baseline\Measurement\BaselineRun;
use Qualimetrix\Infrastructure\Console\Directives\DirectivesCommand;
use Qualimetrix\Infrastructure\Console\GraphExport\GraphExportCommand;
use Qualimetrix\Infrastructure\Console\Hook\HookFileTransaction;
use Qualimetrix\Infrastructure\Console\Hook\HookInstallCommand;
use Qualimetrix\Infrastructure\Console\Hook\HookStatusCommand;
use Qualimetrix\Infrastructure\Console\Hook\HookUninstallCommand;
use Qualimetrix\Infrastructure\Console\Invocation\CommandDefinition\CheckCommandDefinition;
use Qualimetrix\Infrastructure\Console\Invocation\Configuration\ConfigurationInputAdapter;
use Qualimetrix\Infrastructure\Console\Profile\ProfilePresenter;
use Qualimetrix\Infrastructure\Console\Profile\ProfileSummaryRenderer;
use Qualimetrix\Infrastructure\Console\Progress\ProgressConfigurator;
use Qualimetrix\Infrastructure\Console\Refusal\OutOfMemoryHint;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\Console\RuleListing\RuleListingPresenter;
use Qualimetrix\Infrastructure\Console\RuleListing\RulesCommand;
use Qualimetrix\Infrastructure\Console\RunSetup\AnalysisInputPathValidator;
use Qualimetrix\Infrastructure\Console\RunSetup\AnalysisPreflight;
use Qualimetrix\Infrastructure\Console\RunSetup\AnalysisRuntimeConfigurator;
use Qualimetrix\Infrastructure\Console\RunSetup\CheckConfigurationResolvers;
use Qualimetrix\Infrastructure\Console\RunSetup\FindingConfigurationPreparation;
use Qualimetrix\Infrastructure\Console\RunSetup\ProjectSourceConfigurator;
use Qualimetrix\Infrastructure\Console\RunSetup\RunConfigurationPreparation;
use Qualimetrix\Infrastructure\Console\RunSetup\RuntimeConfigurator;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargets;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargetSession;
use Qualimetrix\Infrastructure\Console\RuntimePolicy\ExitCodeResolver;
use Qualimetrix\Infrastructure\Console\RuntimePolicy\ExitPolicySection;
use Qualimetrix\Infrastructure\Console\RuntimePolicy\MemoryLimitSection;
use Qualimetrix\Infrastructure\Console\RuntimePolicy\RuntimeEffects;
use Qualimetrix\Infrastructure\Console\RuntimePolicy\RuntimeLimitsController;
use Qualimetrix\Infrastructure\Console\RuntimePolicy\RuntimeLoggerConfigurator;
use Qualimetrix\Infrastructure\Console\Terminal\ErrorStream;
use Qualimetrix\Infrastructure\Console\Terminal\ReportPublication\ReportPublication;
use Qualimetrix\Infrastructure\Git\GitRepositoryLocator;
use Qualimetrix\Infrastructure\Git\GitRepositoryLocatorInterface;
use Qualimetrix\Infrastructure\Logging\DelegatingLogger;
use Qualimetrix\Infrastructure\Logging\LoggerHolder;
use Qualimetrix\Infrastructure\Rule\Contract\RuleChannelSnapshotFactoryInterface;
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
            $this->srcDir . '/Analysis/Policy/Baseline/{Capture,Ceiling,ChannelRename,Contract,DocumentAccess,DocumentReading,DocumentWriting,Entry,EntryBinding,EntryMaintenance,Explanation,RunCoverage}/*',
            [
                $this->srcDir . '/Analysis/Policy/Baseline/Entry/Baseline.php',
                $this->srcDir . '/Analysis/Policy/Baseline/Entry/BaselineEdge.php',
                $this->srcDir . '/Analysis/Policy/Baseline/Entry/BaselineEntry.php',
                $this->srcDir . '/Analysis/Policy/Baseline/Entry/BaselineEntryMode.php',
                $this->srcDir . '/Analysis/Policy/Baseline/Entry/BaselineIdentity.php',
                $this->srcDir . '/Analysis/Policy/Baseline/Entry/EntrySelector.php',
                $this->srcDir . '/Analysis/Policy/Baseline/Entry/InertBaselineEntry.php',
                $this->srcDir . '/Analysis/Policy/Baseline/Entry/InertEntryReason.php',
                $this->srcDir . '/Analysis/Policy/Baseline/DocumentAccess/BaselineConflictException.php',
                $this->srcDir . '/Analysis/Policy/Baseline/DocumentReading/BaselineEntryRejection.php',
                $this->srcDir . '/Analysis/Policy/Baseline/Capture/BaselineCapture.php',
                $this->srcDir . '/Analysis/Policy/Baseline/Capture/UncapturedGroup.php',
                $this->srcDir . '/Analysis/Policy/Baseline/Capture/UncapturedReason.php',
                $this->srcDir . '/Analysis/Policy/Baseline/DocumentWriting/BaselineDocumentLayout.php',
                $this->srcDir . '/Analysis/Policy/Baseline/DocumentWriting/BaselineEntryOrder.php',
                $this->srcDir . '/Analysis/Policy/Baseline/ChannelRename/BaselineEntryPayload.php',
                $this->srcDir . '/Analysis/Policy/Baseline/DocumentReading/BaselineFormatVersion.php',
                $this->srcDir . '/Analysis/Policy/Baseline/DocumentReading/BaselineFileShape.php',
                $this->srcDir . '/Analysis/Policy/Baseline/Explanation/BoundaryThresholdSources.php',
                $this->srcDir . '/Analysis/Policy/Baseline/Explanation/BoundaryRunFacts.php',
                $this->srcDir . '/Analysis/Policy/Baseline/DocumentReading/BaselineExclusionShape.php',
                $this->srcDir . '/Analysis/Policy/Baseline/DocumentReading/CanonicalEnvelope.php',
                $this->srcDir . '/Analysis/Policy/Baseline/DocumentReading/BaselineEntryShape.php',
                $this->srcDir . '/Analysis/Policy/Baseline/Explanation/CurrentAbsentMeasurement.php',
                $this->srcDir . '/Analysis/Policy/Baseline/ChannelRename/ChannelRenameMap.php',
                $this->srcDir . '/Analysis/Policy/Baseline/ChannelRename/ChannelRenameReport.php',
                $this->srcDir . '/Analysis/Policy/Baseline/ChannelRename/ChannelRenameRefusal.php',
                $this->srcDir . '/Analysis/Policy/Baseline/Explanation/ExplainedSubject.php',
                $this->srcDir . '/Analysis/Policy/Baseline/Ceiling/**',
                $this->srcDir . '/Analysis/Policy/Baseline/Contract/**',
                $this->srcDir . '/Analysis/Policy/Baseline/EntryBinding/**',
            ],
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

        $container->register(ChannelFileScope::class)
            ->setFactory([DeclaredChannelFileScope::class, 'create']);

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
                new Reference(UnusedEntryAudit::class),
                new Reference(ChannelFileScope::class),
            ]);

        // MeasuredFindingSet — the single definition of the set a baseline
        // measures. The pipeline runs its stages; baseline commands ask it
        // for the set directly.
        $container->register(MeasuredFindingSet::class)
            ->setAutowired(true);
    }

    private function registerRuntimePreparation(ContainerBuilder $container): void
    {
        $container->register(FindingProjectionOptionsResolver::class)->setAutowired(true);
        $container->register(ProjectScopePresenter::class)->setAutowired(true);
        $container->register(CheckReportDelivery::class)->setAutowired(true);
        $container->register(FindingDiagnostics::class)->setAutowired(true);
        $container->register(CheckFindingProjection::class)->setAutowired(true);
        $container->register(ReportFormatter::class)->setAutowired(true);
        $container->register(CheckReportRenderer::class)->setAutowired(true);
        $container->register(ReportPublication::class)->setAutowired(true);
        $container->register(CacheRunLifecycle::class)->setAutowired(true)->setPublic(true);
        $container->register(RuntimeEffects::class)->setAutowired(true);
        $container->register(CheckCommandDefinition::class)->setAutowired(true);

        $container->register(RunConfigurationPreparation::class)->setAutowired(true);
        $container->register(ObservedProjectScopeReasons::class)->setAutowired(true);

        $container->register(AnalysisRuntimeConfigurator::class)
            ->setAutowired(true);

        $container->register(RuntimeLoggerConfigurator::class)
            ->setAutowired(true)
            ->setArgument('$loggerHolder', new Reference(LoggerHolder::class))
            ->setArgument('$runTargets', new Reference(RunTargets::class));

        $container->register(ProjectSourceConfigurator::class)
            ->setAutowired(true);

        // RuntimeConfigurator owns cross-cutting setup and resets owner-local
        // runtime state before each configuration resolution.
        $container->register(RuntimeConfigurator::class)
            ->setAutowired(true)
            ->setPublic(true);

        // ProfileSummaryRenderer (stateless, no dependencies)
        $container->register(ProfileSummaryRenderer::class);

        $container->register(RunTargetSession::class)
            ->setArguments([
                new Reference(RunTargets::class),
                new Reference(RefusalPresenter::class),
                new Reference(CacheRunLifecycle::class),
            ]);

        $container->register(ProgressConfigurator::class)
            ->setAutowired(true);
        $container->register(RuntimeLimitsController::class)
            ->setArguments([new Reference(OutOfMemoryHint::class)]);
        $container->register(FindingConfigurationPreparation::class)
            ->setAutowired(true);

        // ProfilePresenter for profiling output
        $container->register(ProfilePresenter::class)
            ->setAutowired(true);

        $container->register(FormatterContextFactory::class)
            ->setAutowired(true);

        $container->register(ExitCodeResolver::class)
            ->setAutowired(true);

        // What --namespace/--class selects; ReportAssembler and the autowired
        // formatter sections both resolve it from here.
        $container->register(FindingFilter::class);

        // ReportAssembler for formatting/output of analysis results
        $container->register(ReportAssembler::class)
            ->setAutowired(true);

        // FindingProjection
        $container->register(BaselineProjectionCoverage::class)
            ->setAutowired(true);
        $container->register(FindingProjection::class)
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
                new Reference(DelegatingLogger::class),
            ]);
        $container->register(
            'Qualimetrix\\Infrastructure\\Console\\CheckScope\\ScopeWarningChecker',
            'Qualimetrix\\Infrastructure\\Console\\CheckScope\\ScopeWarningChecker',
        );
        $container->register(
            'Qualimetrix\\Infrastructure\\Console\\CheckScope\\CheckScopeResolver',
            'Qualimetrix\\Infrastructure\\Console\\CheckScope\\CheckScopeResolver',
        )
            ->setAutowired(true)
            ->setArguments([
                new Reference('Qualimetrix\\Infrastructure\\Git\\GitScopeResolver'),
                new Reference('Qualimetrix\\Infrastructure\\Console\\CheckScope\\ScopeWarningChecker'),
            ]);
        $container->register(CheckConfigurationResolvers::class)
            ->setAutowired(true);

        $container->register(CheckCommand::class)
            ->setAutowired(true)
            ->setPublic(true);
    }

    private function registerHookDelivery(ContainerBuilder $container): void
    {
        $container->register(HookFileTransaction::class)
            ->setArgument('$errorStream', new Reference(ErrorStream::class));
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
                new Reference(HookFileTransaction::class),
            ])
            ->setPublic(true);

        // HookUninstallCommand
        $container->register(HookUninstallCommand::class)
            ->setArguments([
                new Reference(GitRepositoryLocator::class),
                new Reference(RunningBinaryLocator::class),
                new Reference(HookFileTransaction::class),
            ])
            ->setPublic(true);

        // HookStatusCommand
        $container->register(HookStatusCommand::class)
            ->setArguments([
                new Reference(GitRepositoryLocator::class),
                new Reference(RunningBinaryLocator::class),
                new Reference(HookFileTransaction::class),
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
                new Reference(RuleChannelSnapshotFactoryInterface::class),
                new Reference(RuleEnablementResolver::class),
                new Reference(RuleListingPresenter::class),
                new Reference(ConfigurationInputAdapter::class),
                new Reference(ComputedMetricConfiguratorInterface::class),
            ])
            ->setPublic(true);

        $container->register(self::DEPENDENCY_GRAPH_ANALYZER, self::DEPENDENCY_GRAPH_ANALYZER_CLASS)
            ->setArguments([
                new Reference(ProjectFilesInterface::class),
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
                new Reference(RunTargetSession::class),
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
                new Reference(RuleConfigurationInterface::class),
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
                new Reference(ErrorStream::class),
            ])
            ->addMethodCall(...$refusalPresenterCall)
            ->setPublic(true);

        $container->register(BaselineUpdateCommand::class)
            ->setArguments([
                new Reference(BaselineRun::class),
                new Reference(BaselineLoader::class),
                new Reference(BaselineDocumentReader::class),
                new Reference(BaselineUpdater::class),
                new Reference(BaselineWriter::class),
                new Reference(RunRuleCoverage::class),
            ])
            ->addMethodCall(...$refusalPresenterCall)
            ->setPublic(true);

        $container->register(BaselineCleanupCommand::class)
            ->setArguments([
                new Reference(BaselineRun::class),
                new Reference(BaselineLoader::class),
                new Reference(BaselineDocumentReader::class),
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
                new Reference(BaselineDocumentReader::class),
            ])
            ->addMethodCall(...$refusalPresenterCall)
            ->setPublic(true);

        $container->register(BaselineExplainCommand::class)
            ->setArguments([
                new Reference(BaselineRun::class),
                new Reference(BaselineLoader::class),
                new Reference(BaselineDocumentReader::class),
                new Reference(BoundaryExplanationService::class),
                new Reference(BaselineConfiguredThresholds::class),
                new Reference(ChannelDeclarationRegistryInterface::class),
            ])
            ->addMethodCall(...$refusalPresenterCall)
            ->setPublic(true);
    }
}
