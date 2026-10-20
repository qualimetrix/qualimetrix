<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Console\RuleListing;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;

use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\Selection\RuleEnablementResolver;
use Qualimetrix\Infrastructure\Console\Invocation\Configuration\ConfigurationInputAdapter;
use Qualimetrix\Infrastructure\Console\RuleListing\RuleListingPresenter;
use Qualimetrix\Infrastructure\Console\RuleListing\RulesCommand;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ContainerConfiguratorInterface;
use Qualimetrix\Infrastructure\Rule\Contract\RuleChannelSnapshotFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class RuleListingConfigurator implements ContainerConfiguratorInterface
{
    public function configure(ContainerBuilder $container): void
    {
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
    }
}
