<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\DependencyInjection\CompilerPass;

use AiMessDetector\Configuration\RuleOptionsFactory;
use AiMessDetector\Core\Rule\RuleInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Automatically registers Options classes for Rules.
 *
 * For each tagged Rule, this pass:
 * 1. Calls Rule::getOptionsClass() to get the Options class
 * 2. Registers the Options class with RuleOptionsFactory::create() as factory
 * 3. Binds the Options to the Rule via setArgument('$options', ...)
 *
 * This allows Rules to be auto-registered via registerClasses() without
 * manual Options registration in ContainerFactory.
 *
 * Must run BEFORE RuleCompilerPass so Options are available when Rules are collected.
 */
final class RuleOptionsCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // RuleOptionsFactory is synthetic, so use has() instead of hasDefinition()
        if (!$container->has(RuleOptionsFactory::class)) {
            return;
        }

        foreach ($container->findTaggedServiceIds(RuleCompilerPass::TAG) as $ruleId => $tags) {
            $ruleDefinition = $container->getDefinition($ruleId);
            $ruleClass = $ruleDefinition->getClass();

            if ($ruleClass === null) {
                continue;
            }

            // Ensure rule class implements RuleInterface and has getOptionsClass
            if (!is_a($ruleClass, RuleInterface::class, true)) {
                continue;
            }

            // Get Options class from rule
            /** @var class-string<RuleInterface> $ruleClass */
            $optionsClass = $ruleClass::getOptionsClass();

            // Get rule NAME constant for factory
            $ruleName = $ruleClass::NAME;

            // Register Options service if not already registered
            if (!$container->hasDefinition($optionsClass)) {
                $container->register($optionsClass)
                    ->setFactory([new Reference(RuleOptionsFactory::class), 'create'])
                    ->setArguments([$ruleName, $optionsClass]);
                // Note: Options are NOT lazy - they're simple value objects
            }

            // Bind Options to Rule
            $ruleDefinition->setArgument('$options', new Reference($optionsClass));
        }
    }
}
