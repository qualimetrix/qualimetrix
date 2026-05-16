<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\CompilerPass;

use Qualimetrix\Infrastructure\Parallel\Strategy\StrategySelector;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Collects collector and rule class names and passes them to StrategySelector.
 *
 * This ensures that parallel workers use the same set of collectors and
 * rules as configured in the DI container, avoiding manual synchronization.
 *
 * The class names are extracted from tagged services and passed as
 * constructor arguments to StrategySelector, which then configures
 * AmphpParallelStrategy. Rule classes flow through the same channel so
 * each worker can rebuild its own threshold-override validator map via
 * {@see \Qualimetrix\Baseline\Suppression\RuleValidatorMapFactory}.
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

        // Collect rule class names (workers rebuild the threshold-override validator map locally)
        $ruleClasses = [];
        foreach ($container->findTaggedServiceIds(RuleRegistryCompilerPass::TAG) as $id => $tags) {
            $definition = $container->getDefinition($id);
            $ruleClasses[] = $definition->getClass() ?? $id;
        }

        // Pass collector and rule classes to StrategySelector
        $definition = $container->getDefinition(StrategySelector::class);
        $definition->setArgument('$collectorClasses', $collectorClasses);
        $definition->setArgument('$derivedCollectorClasses', $derivedCollectorClasses);
        $definition->setArgument('$ruleClasses', $ruleClasses);
    }
}
