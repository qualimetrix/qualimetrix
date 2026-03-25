<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Infrastructure\Rule\RuleRegistry;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

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
    }

    /**
     * Registers all rules automatically via registerClasses().
     *
     * Rules are discovered from src/Rules/**\/*Rule.php and auto-tagged via
     * registerForAutoconfiguration(RuleInterface::class). Their Options
     * are registered by RuleOptionsCompilerPass using Rule::getOptionsClass().
     *
     * This approach eliminates manual registration when adding new rules:
     * just create the Rule class and Options class, and they're automatically
     * registered without touching ContainerFactory.
     *
     * NOTE: Autowiring is DISABLED for rules because their constructor takes
     * RuleOptionsInterface which requires CompilerPass to resolve correctly.
     * RuleOptionsCompilerPass injects the correct Options reference.
     */
    private function registerRules(ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator($this->srcDir));

        // Auto-register all *Rule.php from src/Rules/**
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
    }
}
