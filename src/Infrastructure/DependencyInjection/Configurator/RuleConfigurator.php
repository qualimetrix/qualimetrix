<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Configuration\Contract\KnownRuleNamesProviderInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Infrastructure\Rule\ChannelDeclarationRegistry;
use Qualimetrix\Infrastructure\Rule\Contract\RuleChannelSnapshotFactoryInterface;
use Qualimetrix\Infrastructure\Rule\KnownRuleNamesAdapter;
use Qualimetrix\Infrastructure\Rule\RuleChannelRegistry;
use Qualimetrix\Infrastructure\Rule\RuleRegistry;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Configures rules and rule registry.
 */
final class RuleConfigurator implements ContainerConfiguratorInterface
{
    public function configure(ContainerBuilder $container): void
    {
        $this->registerRuleRegistry($container);
        $this->registerChannelDeclarationRegistry($container);
        $this->registerRuleChannelSelection($container);
    }

    private function registerRuleRegistry(ContainerBuilder $container): void
    {
        // RuleRegistry will have rule classes injected by RuleRegistryCompilerPass
        $container->register(RuleRegistry::class)
            ->setArguments(['$ruleClasses' => []])
            ->setPublic(true);

        $container->setAlias(RuleRegistryInterface::class, RuleRegistry::class)
            ->setPublic(true);

        // KnownRuleNamesAdapter will have rule classes injected by RuleRegistryCompilerPass
        $container->register(KnownRuleNamesAdapter::class)
            ->setArguments(['$ruleClasses' => []])
            ->setPublic(false);

        $container->setAlias(KnownRuleNamesProviderInterface::class, KnownRuleNamesAdapter::class);
    }

    private function registerChannelDeclarationRegistry(ContainerBuilder $container): void
    {
        $computedMetricCatalog = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\ComputedMetricDefinitionCatalogInterface';

        // ChannelDeclarationRegistry will have the static declaration map and
        // the computed-metric family discriminator injected by
        // ChannelDeclarationCompilerPass.
        $container->register(ChannelDeclarationRegistry::class)
            ->setArguments([
                '$staticDeclarations' => [],
                '$computedMetricRuleName' => '',
                '$definitionCatalog' => new Reference($computedMetricCatalog),
            ])
            ->setPublic(true);

        $container->setAlias(ChannelDeclarationRegistryInterface::class, ChannelDeclarationRegistry::class)
            ->setPublic(true);
    }

    private function registerRuleChannelSelection(ContainerBuilder $container): void
    {
        $container->register(RuleChannelRegistry::class)
            ->setArguments([
                '$staticChannelKeysByProducer' => [],
                '$computedMetricRuleName' => '',
                '$definitions' => new Definition(
                    'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\ResolvedComputedMetricDefinitions',
                    [[]],
                ),
            ]);
        $container->setAlias(RuleChannelRegistryInterface::class, RuleChannelRegistry::class);
        $container->setAlias(RuleChannelSnapshotFactoryInterface::class, RuleChannelRegistry::class);

        $container->register(RuleSelector::class)
            ->setArguments([
                new Reference(RuleChannelRegistryInterface::class),
            ]);
    }
}
