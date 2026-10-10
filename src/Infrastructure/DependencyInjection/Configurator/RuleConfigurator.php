<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Finding\ChannelPresentationView;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelPresentationInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;
use Qualimetrix\Infrastructure\Rule\ComputedMetricChannelPresentation;
use Qualimetrix\Infrastructure\Rule\ConfigurationValidatorRegistry;
use Qualimetrix\Infrastructure\Rule\Contract\RuleChannelSnapshotFactoryInterface;
use Qualimetrix\Infrastructure\Rule\RuleRegistry;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Configures rules and rule registry.
 */
final class RuleConfigurator implements ContainerConfiguratorInterface
{
    public function configure(ContainerBuilder $container): void
    {
        $this->registerRuleRegistry($container);
        $this->registerChannelUniverse($container);
        $this->registerChannelPresentation($container);
    }

    private function registerRuleRegistry(ContainerBuilder $container): void
    {
        // RuleRegistry will have rule classes injected by RuleRegistryCompilerPass
        $container->register(RuleRegistry::class)
            ->setArguments(['$ruleClasses' => []])
            ->setPublic(true);

        $container->setAlias(RuleRegistryInterface::class, RuleRegistry::class)
            ->setPublic(true);

        // Filled in by ConfigurationValidatorCompilerPass, the same way
        // RuleRegistry is filled in by RuleRegistryCompilerPass.
        $container->register(ConfigurationValidatorRegistry::class)
            ->setArguments(['$validatorClasses' => []])
            ->setPublic(true);
    }

    /**
     * One instance, four views. The static half of its arguments is filled in
     * by {@see \Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ChannelDeclarationCompilerPass};
     * the computed-metric half is the live definition catalog, which the
     * universe resolves from on every lookup.
     */
    private function registerChannelUniverse(ContainerBuilder $container): void
    {
        $computedMetricCatalog = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\ComputedMetricDefinitionCatalogInterface';

        $metricReachCatalog = 'Qualimetrix\\Analysis\\Evidence\\Measurement\\Contract\\MetricReachCatalogInterface';
        $computedMetricReach = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\ComputedMetricReachInterface';

        $container->register(ChannelUniverse::class)
            ->setArguments([
                '$staticDeclarations' => [],
                '$staticChannelKeysByProducer' => [],
                '$thresholdOverrideSupportByRule' => [],
                '$definitionCatalog' => new Reference($computedMetricCatalog),
                '$metricReachCatalog' => new Reference($metricReachCatalog),
                '$computedMetricReach' => new Reference($computedMetricReach),
            ])
            ->setPublic(true);

        $container->setAlias(ChannelUniverseInterface::class, ChannelUniverse::class)->setPublic(true);
        $container->setAlias(ChannelDeclarationRegistryInterface::class, ChannelUniverse::class)->setPublic(true);
        $container->setAlias(ChannelIdentityInterface::class, ChannelUniverse::class)->setPublic(true);
        $container->setAlias(RuleChannelRegistryInterface::class, ChannelUniverse::class);
        $container->setAlias(RuleChannelSnapshotFactoryInterface::class, ChannelUniverse::class);
    }

    /**
     * `$docsPageByRule` is filled in by {@see \Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ChannelDeclarationCompilerPass},
     * the same pass that already walks every tagged rule service for its name
     * — see {@see \Qualimetrix\Analysis\Finding\ChannelPresentationView}.
     *
     * The public alias resolves to {@see ComputedMetricChannelPresentation},
     * not directly to the Finding-owned view: the view cannot depend on
     * `ComputedMetricDefinitionCatalogInterface` without closing a dependency
     * cycle (see the view's own docblock), so the decorator layers that fact
     * in from Infrastructure, which already depends on both capabilities.
     */
    private function registerChannelPresentation(ContainerBuilder $container): void
    {
        $computedMetricCatalog = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\ComputedMetricDefinitionCatalogInterface';

        $container->register(ChannelPresentationView::class)
            ->setArguments([
                new Reference(ChannelIdentityInterface::class),
                new Reference(RuleExecutionInterface::class),
                '$docsPageByRule' => [],
            ]);

        $container->register(ComputedMetricChannelPresentation::class)
            ->setArguments([
                new Reference(ChannelPresentationView::class),
                new Reference($computedMetricCatalog),
            ])
            ->setPublic(true);

        $container->setAlias(ChannelPresentationInterface::class, ComputedMetricChannelPresentation::class)
            ->setPublic(true);
    }
}
