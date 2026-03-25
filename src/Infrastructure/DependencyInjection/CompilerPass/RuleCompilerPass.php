<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\CompilerPass;

use Qualimetrix\Analysis\RuleExecution\RuleExecutor;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects all services tagged with 'qmx.rule' and injects them into RuleExecutor.
 */
final class RuleCompilerPass implements CompilerPassInterface
{
    public const string TAG = 'qmx.rule';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(RuleExecutor::class)) {
            return;
        }

        $definition = $container->getDefinition(RuleExecutor::class);
        $rules = [];

        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $rules[] = new Reference($id);
        }

        // Use index 0 instead of named argument to avoid conflicts with TYPE_BEFORE_REMOVING phase
        $definition->setArgument(0, $rules);
    }
}
