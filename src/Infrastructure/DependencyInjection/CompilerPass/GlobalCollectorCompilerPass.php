<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\CompilerPass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects all services tagged with 'qmx.global_collector'
 * and injects them into the Measurement aggregation service.
 */
final class GlobalCollectorCompilerPass implements CompilerPassInterface
{
    public const string TAG = 'qmx.global_collector';

    private const string RUNNER_SERVICE_ID = 'qmx.measurement.aggregation';

    public function process(ContainerBuilder $container): void
    {
        // Collect global collectors
        $collectors = [];
        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $collectors[] = new Reference($id);
        }

        // Inject into the internal Measurement runner without importing it.
        if ($container->hasDefinition(self::RUNNER_SERVICE_ID)) {
            $definition = $container->getDefinition(self::RUNNER_SERVICE_ID);
            $definition->setArgument('$collectors', $collectors);
        }
    }
}
