<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Console\RunSetup;

use Qualimetrix\Analysis\Run\Configuration\PathsSection;

use Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage;
use Qualimetrix\Analysis\Run\Configuration\RunConfigurationResolver;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfigurationResolverInterface;
use Qualimetrix\Infrastructure\Console\Invocation\Configuration\ConfigurationInputAdapter;
use Qualimetrix\Infrastructure\Console\RunSetup\CapabilityState\AnalysisRuntimeConfigurator;
use Qualimetrix\Infrastructure\Console\RunSetup\Execution\RunConfigurationPreparation;
use Qualimetrix\Infrastructure\Console\RunSetup\Finding\FindingConfigurationPreparation;
use Qualimetrix\Infrastructure\Console\RunSetup\Input\AnalysisInputPathValidator;
use Qualimetrix\Infrastructure\Console\RunSetup\Input\AnalysisPreflight;
use Qualimetrix\Infrastructure\Console\RunSetup\ProjectSourceConfigurator;
use Qualimetrix\Infrastructure\Console\RunSetup\RuntimeConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ContainerConfiguratorInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class RunSetupConfigurator implements ContainerConfiguratorInterface
{
    public function configure(ContainerBuilder $container): void
    {
        $container->register(ConfigurationInputAdapter::class)
                    ->setAutowired(true);
        $container->register(PathsSection::class)->setAutoconfigured(true);
        $container->register(RunConfigurationResolver::class)
                    ->setArgument('$projectScopeCoverage', new Reference(ProjectScopeCoverage::class));
        $container->setAlias(RunConfigurationResolverInterface::class, RunConfigurationResolver::class);
        $container->register(RunConfigurationPreparation::class)->setAutowired(true);
        $container->register(AnalysisRuntimeConfigurator::class)
                    ->setAutowired(true);
        $container->register(ProjectSourceConfigurator::class)
                    ->setAutowired(true);
        $container->register(RuntimeConfigurator::class)
                    ->setAutowired(true)
                    ->setPublic(true);
        $container->register(FindingConfigurationPreparation::class)
                    ->setAutowired(true);
        $container->register(AnalysisInputPathValidator::class);
        $container->register(AnalysisPreflight::class)
                    ->setAutowired(true);
    }
}
