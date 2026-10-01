<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Finding\Contract\Configuration\RuleOptionsBuild;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\Selection\RuleEnablementResolver;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RulesSection;
use Qualimetrix\Analysis\Finding\RuleExecution;
use Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionRule;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/** Composes Finding internals behind their exact public contracts. */
final class FindingConfigurator implements ContainerConfiguratorInterface
{
    private const string UNBOUND_SUPPRESSION_AUDIT_CLASS = 'Qualimetrix\\Analysis\\Finding\\SuppressionBinding\\UnboundSuppressionAudit';

    public function configure(ContainerBuilder $container): void
    {
        $container->register(RuleOptionsRegistry::class);
        $container->setAlias(RuleConfigurationInterface::class, RuleOptionsRegistry::class)
            ->setPublic(true);

        $container->register(RuleEnablementResolver::class);

        $container->register(RuleOptionsBuild::class)
            ->setArguments([
                new Reference(RuleExecutionInterface::class),
            ])
            ->setPublic(true);

        $container->register(RuleExecution::class)
            ->setArguments([
                '$rules' => [],
                '$profiler' => new Reference(ProfilerInterface::class),
                '$ruleOptionsRegistry' => new Reference(RuleOptionsRegistry::class),
                '$configurationValidators' => [],
                '$classlessProducers' => [],
            ]);
        $container->setAlias(RuleExecutionInterface::class, RuleExecution::class)
            ->setPublic(true);

        foreach (['rules', 'only_rules', 'disabled_rules'] as $root) {
            $container->register(RulesSection::class . '.' . $root, RulesSection::class)
                ->setArguments([new Reference(RuleExecutionInterface::class), $root])
                ->addTag(ConfigurationConfigurator::SECTION_TAG);
        }

        $this->registerUnboundSuppressionProducer($container);
    }

    /**
     * Finding's own producer: the rule that gives the three channels an
     * identity, and the audit that measures and builds what they report.
     *
     * The rule is registered by name rather than found by a scan, for the
     * reason every capability configurator registers its own roots by name —
     * a pattern over this namespace would silently enrol the next class added
     * to it.
     *
     * The shared audit reads invocation options through the live registry.
     */
    private function registerUnboundSuppressionProducer(ContainerBuilder $container): void
    {
        $container->register(self::UNBOUND_SUPPRESSION_AUDIT_CLASS, self::UNBOUND_SUPPRESSION_AUDIT_CLASS)
            ->setArguments([
                new Reference(RuleExecutionInterface::class),
                new Reference(RuleOptionsRegistry::class),
            ])
            ->setLazy(true);

        $container->register(UnboundSuppressionRule::class, UnboundSuppressionRule::class)
            ->setAutoconfigured(true)
            ->setAutowired(false)
            ->setLazy(true);
    }
}
