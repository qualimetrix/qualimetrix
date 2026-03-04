<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\DependencyInjection\CompilerPass;

use AiMessDetector\Analysis\Aggregation\GlobalCollectorRunner;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects all services tagged with 'aimd.global_collector'
 * and injects them into GlobalCollectorRunner.
 */
final class GlobalCollectorCompilerPass implements CompilerPassInterface
{
    public const string TAG = 'aimd.global_collector';

    public function process(ContainerBuilder $container): void
    {
        // Collect global collectors
        $collectors = [];
        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $collectors[] = new Reference($id);
        }

        // Inject into GlobalCollectorRunner
        if ($container->hasDefinition(GlobalCollectorRunner::class)) {
            $definition = $container->getDefinition(GlobalCollectorRunner::class);
            $definition->setArgument('$collectors', $collectors);
        }
    }
}
