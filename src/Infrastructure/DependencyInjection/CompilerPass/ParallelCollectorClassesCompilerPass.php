<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\CompilerPass;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;

use Qualimetrix\Infrastructure\DependencyInjection\RuleCatalog\RuleRegistryCompilerPass;
use Qualimetrix\Infrastructure\Parallel\FileProcessingTaskFactory;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Collects collector and rule class names and passes them to FileProcessingTaskFactory.
 *
 * This ensures that parallel workers use the same set of collectors and
 * rules as configured in the DI container, avoiding manual synchronization.
 *
 * The class names are extracted from tagged services and passed as
 * constructor arguments to FileProcessingTaskFactory. Rule classes flow through
 * the same channel so
 * each worker can rebuild its own threshold-override validator map via
 * {@see \Qualimetrix\Analysis\Policy\Inline\Contract\RuleValidatorMapFactory}.
 */
final class ParallelCollectorClassesCompilerPass implements CompilerPassInterface, ConsumerBoundPassInterface
{
    public static function consumerServiceIds(): array
    {
        return [FileProcessingTaskFactory::class];
    }

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(FileProcessingTaskFactory::class)) {
            return;
        }

        $collectorClasses = $this->classesForTag($container, CollectorCompilerPass::TAG);
        $derivedCollectorClasses = $this->classesForTag($container, CollectorCompilerPass::TAG_DERIVED);
        $ruleClasses = $this->classesForTag($container, RuleRegistryCompilerPass::TAG);

        /** @var list<class-string<RuleDefinitionInterface>> $ruleClasses */
        $definition = $container->getDefinition(FileProcessingTaskFactory::class);
        $definition->setArgument('$collectorClasses', $collectorClasses);
        $definition->setArgument('$derivedCollectorClasses', $derivedCollectorClasses);
        $definition->setArgument('$ruleClasses', $ruleClasses);
    }

    /** @return list<string> */
    private function classesForTag(ContainerBuilder $container, string $tag): array
    {
        $classes = [];

        foreach ($container->findTaggedServiceIds($tag) as $id => $_tags) {
            $classes[] = $container->getDefinition($id)->getClass() ?? $id;
        }

        return $classes;
    }
}
