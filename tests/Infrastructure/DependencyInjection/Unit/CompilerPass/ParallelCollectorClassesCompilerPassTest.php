<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityRule;
use Qualimetrix\Analysis\Evidence\Complexity\CyclomaticComplexityCollector;
use Qualimetrix\Analysis\Evidence\Complexity\NpathComplexityCollector;
use Qualimetrix\Analysis\Evidence\Maintainability\MaintainabilityIndexCollector;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\CollectorCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ParallelCollectorClassesCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleRegistryCompilerPass;
use Qualimetrix\Infrastructure\Parallel\FileProcessingTaskFactory;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(ParallelCollectorClassesCompilerPass::class)]
final class ParallelCollectorClassesCompilerPassTest extends TestCase
{
    #[Test]
    public function itPassesCollectorAndRuleClassNamesToFileProcessingTaskFactory(): void
    {
        $container = new ContainerBuilder();
        $container->register(FileProcessingTaskFactory::class);
        $container->register(CyclomaticComplexityCollector::class)
            ->addTag(CollectorCompilerPass::TAG);
        $container->register(NpathComplexityCollector::class)
            ->addTag(CollectorCompilerPass::TAG);
        $container->register(MaintainabilityIndexCollector::class)
            ->addTag(CollectorCompilerPass::TAG_DERIVED);
        $container->register(ComplexityRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);

        $pass = new ParallelCollectorClassesCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(FileProcessingTaskFactory::class);

        $collectorClasses = $definition->getArgument('$collectorClasses');
        self::assertCount(2, $collectorClasses);
        self::assertContains(CyclomaticComplexityCollector::class, $collectorClasses);
        self::assertContains(NpathComplexityCollector::class, $collectorClasses);

        $derivedClasses = $definition->getArgument('$derivedCollectorClasses');
        self::assertCount(1, $derivedClasses);
        self::assertContains(MaintainabilityIndexCollector::class, $derivedClasses);

        self::assertSame([ComplexityRule::class], $definition->getArgument('$ruleClasses'));
    }

    #[Test]
    public function itDoesNothingWhenFileProcessingTaskFactoryIsNotRegistered(): void
    {
        $container = new ContainerBuilder();
        $container->register(CyclomaticComplexityCollector::class)
            ->addTag(CollectorCompilerPass::TAG);

        $pass = new ParallelCollectorClassesCompilerPass();
        $pass->process($container);

        self::assertFalse($container->hasDefinition(FileProcessingTaskFactory::class));
    }

    #[Test]
    public function itSetsEmptyArraysWhenNoTaggedServices(): void
    {
        $container = new ContainerBuilder();
        $container->register(FileProcessingTaskFactory::class);

        $pass = new ParallelCollectorClassesCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(FileProcessingTaskFactory::class);

        self::assertSame([], $definition->getArgument('$collectorClasses'));
        self::assertSame([], $definition->getArgument('$derivedCollectorClasses'));
        self::assertSame([], $definition->getArgument('$ruleClasses'));
    }

    #[Test]
    public function itExtractsClassNameFromDefinitionWhenDifferentFromServiceId(): void
    {
        $container = new ContainerBuilder();
        $container->register(FileProcessingTaskFactory::class);

        // Register with an alias service ID but explicit class name
        $container->register('app.collector.ccn', CyclomaticComplexityCollector::class)
            ->addTag(CollectorCompilerPass::TAG);
        $container->register('app.collector.derived', MaintainabilityIndexCollector::class)
            ->addTag(CollectorCompilerPass::TAG_DERIVED);

        $pass = new ParallelCollectorClassesCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(FileProcessingTaskFactory::class);

        $collectorClasses = $definition->getArgument('$collectorClasses');
        self::assertCount(1, $collectorClasses);
        self::assertSame(CyclomaticComplexityCollector::class, $collectorClasses[0]);

        $derivedClasses = $definition->getArgument('$derivedCollectorClasses');
        self::assertCount(1, $derivedClasses);
        self::assertSame(MaintainabilityIndexCollector::class, $derivedClasses[0]);
    }
}
