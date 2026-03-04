<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\DependencyInjection\CompilerPass;

use AiMessDetector\Infrastructure\Rule\RuleRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Collects rule classes from tagged services and injects them into RuleRegistry.
 *
 * This allows RuleRegistry to work with class names instead of instances,
 * enabling metadata extraction via reflection without instantiation.
 */
final class RuleRegistryCompilerPass implements CompilerPassInterface
{
    public const string TAG = 'aimd.rule';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(RuleRegistry::class)) {
            return;
        }

        $definition = $container->getDefinition(RuleRegistry::class);
        $ruleClasses = [];

        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $serviceDefinition = $container->getDefinition($id);
            $class = $serviceDefinition->getClass();

            if ($class !== null) {
                $ruleClasses[] = $class;
            }
        }

        $definition->setArgument('$ruleClasses', $ruleClasses);
    }
}
