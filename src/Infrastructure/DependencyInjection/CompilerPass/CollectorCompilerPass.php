<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\CompilerPass;

use Qualimetrix\Analysis\Collection\Metric\CompositeCollector;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects all services tagged with 'qmx.collector' and 'qmx.derived_collector'
 * and injects them into CompositeCollector.
 */
final class CollectorCompilerPass implements CompilerPassInterface
{
    public const string TAG = 'qmx.collector';
    public const string TAG_DERIVED = 'qmx.derived_collector';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(CompositeCollector::class)) {
            return;
        }

        $definition = $container->getDefinition(CompositeCollector::class);

        // Collect base collectors
        $collectors = [];
        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $collectors[] = new Reference($id);
        }
        $definition->setArgument(0, $collectors);

        // Collect derived collectors
        $derivedCollectors = [];
        foreach ($container->findTaggedServiceIds(self::TAG_DERIVED) as $id => $tags) {
            $derivedCollectors[] = new Reference($id);
        }
        $definition->setArgument(1, $derivedCollectors);
    }
}
