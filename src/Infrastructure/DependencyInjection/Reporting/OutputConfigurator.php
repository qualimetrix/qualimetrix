<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Reporting;

use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelDeclarationRegistryInterface;

use Qualimetrix\Analysis\Finding\Contract\Filter\ChannelFileScope;
use Qualimetrix\Analysis\Policy\Baseline\DocumentReading\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\EntryBinding\UnusedEntryAudit;
use Qualimetrix\Analysis\Policy\Inline\Contract\AnnotationSuppressionInterface;
use Qualimetrix\Infrastructure\DependencyInjection\ChannelCatalog\DeclaredChannelFileScope;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ContainerConfiguratorInterface;
use Qualimetrix\Infrastructure\Logging\DelegatingLogger;
use Qualimetrix\Reporting\Configuration\OutputFormatResolver;
use Qualimetrix\Reporting\Configuration\OutputFormatSection;
use Qualimetrix\Reporting\Configuration\OutputFormatVocabulary;
use Qualimetrix\Reporting\Contract\Formatting\FormatterRegistryInterface;
use Qualimetrix\Reporting\Contract\OutputFormatResolverInterface;
use Qualimetrix\Reporting\FindingProjection\Configuration\ConfiguredFindingExclusionsResolver;
use Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusionsResolverInterface;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeQueryInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Composes report formats, their projections and output configuration.
 *
 * @qmx-threshold coupling.cbo 24 -- Formatter registration and output policy are separate operations; the remaining composition names the explicit public reporting vocabulary they wire.
 */
final class OutputConfigurator implements ContainerConfiguratorInterface
{
    public function __construct(private readonly string $srcDir) {}

    public function configure(ContainerBuilder $container): void
    {
        $this->registerFormatters($container);
        $this->registerGraphProjection($container);
        $this->registerOutputFormat($container);
        $this->registerFindingProjection($container);
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

        $container->register(\Qualimetrix\Reporting\FindingPublication\FindingRecord::class)->setAutowired(true);

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

    private function registerOutputFormat(ContainerBuilder $container): void
    {
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

        // Also read by the Finding enablement resolver at configuration load.
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

    }
}
