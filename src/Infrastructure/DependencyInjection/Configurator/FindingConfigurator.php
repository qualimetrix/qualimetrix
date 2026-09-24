<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Finding\RuleExecution;
use Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionRule;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleOptionsCompilerPass;
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

        $container->register(RuleOptionsFactory::class)
            ->setArguments([
                new Reference(RuleOptionsRegistry::class),
            ])
            ->setPublic(true);

        $container->register(RuleExecution::class)
            ->setArguments([
                '$rules' => [],
                '$profiler' => new Reference(ProfilerInterface::class),
                '$ruleOptionsRegistry' => new Reference(RuleOptionsRegistry::class),
                '$ruleSelector' => new Reference(RuleSelector::class),
                '$configurationValidators' => [],
                '$classlessProducers' => [],
                '$channelIdentity' => new Reference(ChannelIdentityInterface::class),
            ]);
        $container->setAlias(RuleExecutionInterface::class, RuleExecution::class)
            ->setPublic(true);

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
     * The audit answers to the rule's **own** Options service, derived the way
     * {@see RuleOptionsCompilerPass} derives it when it registers that service
     * later in the build, so one setting is read in one place. It is lazy for
     * the reason its discovery sibling is: constructed eagerly, together with
     * the console command that holds its consumer, it would capture the
     * options as they stood before the runtime configuration applied
     * `rules.<name>.enabled` or `--rule-opt`. Its first call is after the run.
     */
    private function registerUnboundSuppressionProducer(ContainerBuilder $container): void
    {
        $container->register(self::UNBOUND_SUPPRESSION_AUDIT_CLASS, self::UNBOUND_SUPPRESSION_AUDIT_CLASS)
            ->setArguments([
                new Reference(RuleOptionsCompilerPass::optionsServiceIdForRule(UnboundSuppressionRule::class)),
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
