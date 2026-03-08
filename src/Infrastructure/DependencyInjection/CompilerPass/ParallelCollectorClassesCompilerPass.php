<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\DependencyInjection\CompilerPass;

use AiMessDetector\Infrastructure\Parallel\Strategy\StrategySelector;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Collects collector class names and passes them to StrategySelector.
 *
 * This ensures that parallel workers use the same set of collectors
 * as configured in the DI container, avoiding manual synchronization.
 *
 * The class names are extracted from tagged services and passed as
 * constructor arguments to StrategySelector, which then configures
 * AmphpParallelStrategy.
 */
final class ParallelCollectorClassesCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(StrategySelector::class)) {
            return;
        }

        // Collect class names for base collectors
        $collectorClasses = [];
        foreach ($container->findTaggedServiceIds(CollectorCompilerPass::TAG) as $id => $tags) {
            $definition = $container->getDefinition($id);
            $collectorClasses[] = $definition->getClass() ?? $id;
        }

        // Collect class names for derived collectors
        $derivedCollectorClasses = [];
        foreach ($container->findTaggedServiceIds(CollectorCompilerPass::TAG_DERIVED) as $id => $tags) {
            $definition = $container->getDefinition($id);
            $derivedCollectorClasses[] = $definition->getClass() ?? $id;
        }

        // Pass collector classes to StrategySelector
        $definition = $container->getDefinition(StrategySelector::class);
        $definition->setArgument('$collectorClasses', $collectorClasses);
        $definition->setArgument('$derivedCollectorClasses', $derivedCollectorClasses);
    }
}
