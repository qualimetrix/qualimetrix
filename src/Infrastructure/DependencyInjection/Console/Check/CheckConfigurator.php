<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Console\Check;

use Qualimetrix\Infrastructure\Console\BaselineCommand\BaselineProjectionCoverage;

use Qualimetrix\Infrastructure\Console\Check\CheckCommand;
use Qualimetrix\Infrastructure\Console\CheckReport\Assembly\ReportAssembler;
use Qualimetrix\Infrastructure\Console\CheckReport\CheckReportDelivery;
use Qualimetrix\Infrastructure\Console\CheckReport\CheckReportRenderer;
use Qualimetrix\Infrastructure\Console\CheckReport\FormatBinding\FormatterContextFactory;
use Qualimetrix\Infrastructure\Console\CheckReport\FormatBinding\ReportFormatter;
use Qualimetrix\Infrastructure\Console\CheckReport\Projection\CheckFindingProjection;
use Qualimetrix\Infrastructure\Console\CheckReport\Projection\FindingDiagnostics;
use Qualimetrix\Infrastructure\Console\CheckReport\Projection\FindingProjection;
use Qualimetrix\Infrastructure\Console\CheckScope\FindingProjectionOptionsResolver;
use Qualimetrix\Infrastructure\Console\CheckScope\ObservedProjectScopeReasons;
use Qualimetrix\Infrastructure\Console\CheckScope\ProjectScopePresenter;
use Qualimetrix\Infrastructure\Console\Invocation\CommandDefinition\CheckCommandDefinition;
use Qualimetrix\Infrastructure\Console\RunSetup\Finding\CheckConfigurationResolvers;
use Qualimetrix\Infrastructure\Console\Terminal\ReportPublication\ReportPublication;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ContainerConfiguratorInterface;
use Qualimetrix\Infrastructure\Logging\DelegatingLogger;
use Qualimetrix\Reporting\DrillDown\FindingFilter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @qmx-threshold coupling.cbo 23 -- Check composition binds input preparation, measured execution and report publication after their registrations were separated into their own operations.
 */
final class CheckConfigurator implements ContainerConfiguratorInterface
{
    public function configure(ContainerBuilder $container): void
    {
        $this->registerScopeProjection($container);
        $this->registerFindingProjection($container);
        $this->registerReportPublication($container);
        $container->register(CheckCommandDefinition::class)->setAutowired(true);
        $container->register(CheckCommand::class)
                    ->setAutowired(true)
                    ->setPublic(true);
    }

    private function registerScopeProjection(ContainerBuilder $container): void
    {
        $container->register(FindingProjectionOptionsResolver::class)->setAutowired(true);
        $container->register(ProjectScopePresenter::class)->setAutowired(true);
        $container->register(ObservedProjectScopeReasons::class)->setAutowired(true);
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
    }

    private function registerFindingProjection(ContainerBuilder $container): void
    {
        $container->register(FindingDiagnostics::class)->setAutowired(true);
        $container->register(CheckFindingProjection::class)->setAutowired(true);
        $container->register(FindingFilter::class);
        $container->register(BaselineProjectionCoverage::class)
                    ->setAutowired(true);
        $container->register(FindingProjection::class)
                    ->setAutowired(true);
    }

    private function registerReportPublication(ContainerBuilder $container): void
    {
        $container->register(CheckReportDelivery::class)->setAutowired(true);
        $container->register(ReportFormatter::class)->setAutowired(true);
        $container->register(CheckReportRenderer::class)->setAutowired(true);
        $container->register(ReportPublication::class)->setAutowired(true);
        $container->register(FormatterContextFactory::class)
                    ->setAutowired(true);
        $container->register(ReportAssembler::class)
                    ->setAutowired(true);
    }
}
