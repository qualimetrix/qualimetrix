<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Configuration\KnownRuleNamesProviderInterface;
use Qualimetrix\Core\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Core\Rule\RuleSelector;
use Qualimetrix\Core\Violation\ChannelDeclarationRegistryInterface;
use Qualimetrix\Infrastructure\Rule\ChannelDeclarationRegistry;
use Qualimetrix\Infrastructure\Rule\KnownRuleNamesAdapter;
use Qualimetrix\Infrastructure\Rule\RuleChannelRegistry;
use Qualimetrix\Infrastructure\Rule\RuleRegistry;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Configures rules and rule registry.
 */
final class RuleConfigurator implements ContainerConfiguratorInterface
{
    public function __construct(
        private readonly string $srcDir,
    ) {}

    public function configure(ContainerBuilder $container): void
    {
        $this->registerRules($container);
        $this->registerRuleRegistry($container);
        $this->registerChannelDeclarationRegistry($container);
        $this->registerRuleChannelSelection($container);
    }

    /**
     * Registers cross-cutting rules from src/Rules/**\/*Rule.php.
     *
     * Rules are auto-tagged via registerForAutoconfiguration(RuleInterface::class)
     * in ContainerFactory. Their Options are registered by RuleOptionsCompilerPass
     * using Rule::getOptionsClass().
     *
     * This approach eliminates manual registration when adding new rules:
     * just create the Rule class and Options class, and they're automatically
     * registered without touching ContainerFactory.
     *
     * Capability-owned rules are registered by their subject configurator —
     * see {@see ArchitectureConfigurator} and {@see DuplicationConfigurator}.
     * This scan covers only the remaining layered rules under src/Rules/.
     *
     * NOTE: Autowiring is DISABLED for rules because their constructor takes
     * RuleOptionsInterface which requires CompilerPass to resolve correctly.
     * RuleOptionsCompilerPass injects the correct Options reference.
     */
    private function registerRules(ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator($this->srcDir));

        // Auto-register all remaining layered *Rule.php from src/Rules/**
        // Classes implementing RuleInterface will be auto-tagged and made lazy
        // via registerForAutoconfiguration() in create()
        // Autowiring is DISABLED - RuleOptionsCompilerPass handles argument injection
        $prototype = (new Definition())
            ->setAutoconfigured(true)
            ->setAutowired(false)
            ->setLazy(true);

        $loader->registerClasses(
            $prototype,
            'Qualimetrix\\Rules\\',
            $this->srcDir . '/Rules/**/*Rule.php',
            $this->srcDir . '/Rules/AbstractRule.php',
        );
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
        // ChannelDeclarationRegistry will have the static declaration map and
        // the computed-metric family discriminator injected by
        // ChannelDeclarationCompilerPass.
        $container->register(ChannelDeclarationRegistry::class)
            ->setArguments([
                '$staticDeclarations' => [],
                '$computedMetricRuleName' => '',
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
            ]);
        $container->setAlias(RuleChannelRegistryInterface::class, RuleChannelRegistry::class);

        $container->register(RuleSelector::class)
            ->setArguments([
                new Reference(RuleChannelRegistryInterface::class),
            ]);
    }
}
