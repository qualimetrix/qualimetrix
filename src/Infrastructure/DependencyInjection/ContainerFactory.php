<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection;

use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfigurableInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\GlobalContextCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricCollectorInterface;
use Qualimetrix\Analysis\Run\Contract\FileSetInspectionParticipantInterface;
use Qualimetrix\Infrastructure\Console\Application\ConsoleDiagnostics;
use Qualimetrix\Infrastructure\Console\Refusal\OutOfMemoryHint;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\Console\Terminal\Diagnostics\ErrorStream;
use Qualimetrix\Infrastructure\DependencyInjection\Architecture\ArchitectureConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Baseline\BaselineCommandsConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Baseline\BaselineConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\ChannelCatalog\ChannelDeclarationCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CircularDependency\CircularDependencyConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\CodeSmell\CodeSmellConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Cohesion\CohesionConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\CollectorCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ConfigurationStageCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ConsumerRegistrationCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\FileSetInspectionParticipantCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\FormatterCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\GlobalCollectorCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\TagPopulationFloorCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\Complexity\ComplexityConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\ComputedMetrics\ComputedMetricsConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Configuration\ConfigurationConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\CoreServicesConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Console\Check\CheckConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Console\Directives\DirectivesConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Console\GraphExport\GraphExportConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Console\Hook\HookConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Console\RuleListing\RuleListingConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Console\RunSetup\RunSetupConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Console\RuntimePolicy\RuntimePolicyConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Coupling\CouplingConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\DependencyModel\DependencyModelConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Design\DesignConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Duplication\DuplicationConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Finding\FindingConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Inline\InlineConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Maintainability\MaintainabilityConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Measurement\MeasurementConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Parallel\CollectorConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Parallel\ParallelCollectorClassesCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\Parsing\ParserConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\ProjectManifest\ProjectManifestConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Reporting\OutputConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\RuleCatalog\ConfigurationValidatorCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\RuleCatalog\RuleCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\RuleCatalog\RuleConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\RuleCatalog\RuleOptionsCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\RuleCatalog\RuleRegistryCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\RuleCatalog\ThresholdValidatorMapCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\Run\AnalysisConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Run\DiscoveryConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Security\SecurityConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Size\SizeConfigurator;
use Qualimetrix\Reporting\Contract\Formatting\FormatterInterface;
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
 * Runtime configuration is composed into owner-local stores by RuntimeConfigurator
 * before analysis begins; RuleOptionsRegistry retains the Finding-owned rule state.
 *
 * Service registration is delegated to dedicated configurators, each responsible
 * for a cohesive group of services. Configurators are bootstrapping-code and
 * are instantiated manually (not via DI).
 *
 * @qmx-threshold coupling.cbo 61 -- This factory explicitly enumerates its configurators and compiler passes; the finite bound keeps additional composition dependencies visible.
 */
final class ContainerFactory
{
    /** @var array<string, positive-int> */
    private const array REQUIRED_TAG_POPULATIONS = [
        RuleCompilerPass::TAG => 1,
        ConfigurationValidatorCompilerPass::TAG => 1,
        CollectorCompilerPass::TAG => 1,
        CollectorCompilerPass::TAG_DERIVED => 1,
        GlobalCollectorCompilerPass::TAG => 1,
        'qmx.cohesion.lcom_configurable_collector' => 1,
        FileSetInspectionParticipantCompilerPass::TAG => 1,
        FormatterCompilerPass::TAG => 1,
        ConfigurationStageCompilerPass::TAG => 1,
    ];
    /**
     * Create a fully configured container.
     *
     * The container starts with default owner-local state. RuntimeConfigurator
     * resolves each CLI/configuration run and applies it before pipeline execution.
     */
    public function create(?ConsoleDiagnostics $diagnostics = null): ContainerBuilder
    {
        $container = $this->configure($diagnostics);
        $container->compile();

        return $container;
    }

    /**
     * Every service definition and compiler pass of the product container,
     * not yet compiled — the container {@see create()} compiles, exposed so a
     * test can take one definition out of the real build and watch it refuse.
     */
    public function configure(?ConsoleDiagnostics $diagnostics = null): ContainerBuilder
    {
        $container = new ContainerBuilder();

        // Register autoconfiguration rules for interface tagging
        $this->registerAutoconfiguration($container);

        // Delegate service registration to configurators
        $srcDir = \dirname(__DIR__, 2); // src/

        $configurators = [
            new CoreServicesConfigurator(),
            new ConfigurationConfigurator($srcDir),
            new ProjectManifestConfigurator(),
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
            new BaselineConfigurator($srcDir),
            new DiscoveryConfigurator(),
            new AnalysisConfigurator(),
            new InlineConfigurator(),
            new RuntimePolicyConfigurator(),
            new RunSetupConfigurator(),
            new OutputConfigurator($srcDir),
            new BaselineCommandsConfigurator(),
            new CheckConfigurator(),
            new HookConfigurator(),
            new DirectivesConfigurator(),
            new RuleListingConfigurator(),
            new GraphExportConfigurator(),
        ];

        foreach ($configurators as $configurator) {
            $configurator->configure($container);
        }

        // One ErrorStream makes progress redraw around every diagnostic writer.
        $container->register(ErrorStream::class)->setSynthetic(true)->setPublic(true);
        $container->register(RefusalPresenter::class)->setSynthetic(true)->setPublic(true);
        $container->register(OutOfMemoryHint::class)->setSynthetic(true)->setPublic(true);

        $diagnostics ??= ConsoleDiagnostics::fresh();
        foreach ([
            ErrorStream::class => $diagnostics->errorStream,
            RefusalPresenter::class => $diagnostics->refusalPresenter,
            OutOfMemoryHint::class => $diagnostics->outOfMemoryHint,
        ] as $id => $service) {
            $definition = $container->getDefinition($id);
            $container->set($id, $service);
            // set() removes the definition that autowiring still needs.
            $container->setDefinition($id, $definition);
        }

        // Add compiler passes
        $this->registerCompilerPasses($container);

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

        // The second producer kind. Lazy for the same reason rules are: a
        // validator reaches into its capability's run state, which does not
        // exist until the run prepares it.
        $container->registerForAutoconfiguration('Qualimetrix\\Analysis\\Finding\\Contract\\ConfigurationValidatorInterface')
            ->addTag(ConfigurationValidatorCompilerPass::TAG)
            ->setLazy(true);

        // Autoconfigure: all collector interfaces get auto-tagged
        $container->registerForAutoconfiguration(MetricCollectorInterface::class)
            ->addTag(CollectorCompilerPass::TAG);

        $container->registerForAutoconfiguration(DerivedCollectorInterface::class)
            ->addTag(CollectorCompilerPass::TAG_DERIVED);

        $container->registerForAutoconfiguration(GlobalContextCollectorInterface::class)
            ->addTag(GlobalCollectorCompilerPass::TAG);

        $container->registerForAutoconfiguration(LcomCollectionConfigurableInterface::class)
            ->addTag('qmx.cohesion.lcom_configurable_collector');

        $container->registerForAutoconfiguration(FileSetInspectionParticipantInterface::class)
            ->addTag(FileSetInspectionParticipantCompilerPass::TAG);

        // Autoconfigure: all formatters get auto-tagged
        $container->registerForAutoconfiguration(FormatterInterface::class)
            ->addTag(FormatterCompilerPass::TAG);

        // Configuration stages autoconfiguration
        $container->registerForAutoconfiguration('Qualimetrix\\Analysis\\Configuration\\Pipeline\\ConfigurationStageInterface')
            ->addTag(ConfigurationStageCompilerPass::TAG);

        // An owner declares its configuration root by registering its section
        // autoconfigured; the pipeline composes the document against it.
        $container->registerForAutoconfiguration('Qualimetrix\\Analysis\\Configuration\\Contract\\Document\\Schema\\DocumentSectionSchemaInterface')
            ->addTag(ConfigurationConfigurator::SECTION_TAG);

    }

    /**
     * Registers all compiler passes in the correct order.
     */
    private function registerCompilerPasses(ContainerBuilder $container): void
    {
        $container->addCompilerPass(
            new TagPopulationFloorCompilerPass(self::REQUIRED_TAG_POPULATIONS),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            75,
        );
        // Ahead of every pass it checks: those at the default priority run
        // after it in this phase, and RuleOptionsCompilerPass/RuleCompilerPass
        // run in a later phase that renames and removes no definition first.
        $container->addCompilerPass(new ConsumerRegistrationCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 50);
        $container->addCompilerPass(new CollectorCompilerPass());
        $container->addCompilerPass(new GlobalCollectorCompilerPass());
        $container->addCompilerPass(new ParallelCollectorClassesCompilerPass());
        $container->addCompilerPass(new FileSetInspectionParticipantCompilerPass());
        $container->addCompilerPass(new RuleRegistryCompilerPass());
        $container->addCompilerPass(new ChannelDeclarationCompilerPass());
        $container->addCompilerPass(new ConfigurationValidatorCompilerPass());
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
