<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\CompilerPass;

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
    private const string COLLECTOR_SERVICE_ID = 'qmx.measurement.file_collector';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::COLLECTOR_SERVICE_ID)) {
            return;
        }

        $definition = $container->getDefinition(self::COLLECTOR_SERVICE_ID);

        // Collect base collectors
        $collectors = [];
        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $collectors[] = new Reference($id);
        }
        $definition->setArgument('$collectors', $collectors);

        // Collect derived collectors
        $derivedCollectors = [];
        foreach ($container->findTaggedServiceIds(self::TAG_DERIVED) as $id => $tags) {
            $derivedCollectors[] = new Reference($id);
        }
        $definition->setArgument('$derivedCollectors', $derivedCollectors);
    }
}
