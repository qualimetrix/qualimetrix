<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\DependencyInjection\CompilerPass;

use AiMessDetector\Infrastructure\DependencyInjection\CompilerPass\CollectorCompilerPass;
use AiMessDetector\Infrastructure\DependencyInjection\CompilerPass\ParallelCollectorClassesCompilerPass;
use AiMessDetector\Infrastructure\Parallel\Strategy\StrategySelector;
use AiMessDetector\Metrics\Complexity\CyclomaticComplexityCollector;
use AiMessDetector\Metrics\Complexity\NpathComplexityCollector;
use AiMessDetector\Metrics\Maintainability\MaintainabilityIndexCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(ParallelCollectorClassesCompilerPass::class)]
final class ParallelCollectorClassesCompilerPassTest extends TestCase
{
    #[Test]
    public function passesCollectorClassNamesToStrategySelector(): void
    {
        $container = new ContainerBuilder();
        $container->register(StrategySelector::class);
        $container->register(CyclomaticComplexityCollector::class)
            ->addTag(CollectorCompilerPass::TAG);
        $container->register(NpathComplexityCollector::class)
            ->addTag(CollectorCompilerPass::TAG);
        $container->register(MaintainabilityIndexCollector::class)
            ->addTag(CollectorCompilerPass::TAG_DERIVED);

        $pass = new ParallelCollectorClassesCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(StrategySelector::class);

        $collectorClasses = $definition->getArgument('$collectorClasses');
        self::assertCount(2, $collectorClasses);
        self::assertContains(CyclomaticComplexityCollector::class, $collectorClasses);
        self::assertContains(NpathComplexityCollector::class, $collectorClasses);

        $derivedClasses = $definition->getArgument('$derivedCollectorClasses');
        self::assertCount(1, $derivedClasses);
        self::assertContains(MaintainabilityIndexCollector::class, $derivedClasses);
    }

    #[Test]
    public function doesNothingWhenStrategySelectorNotRegistered(): void
    {
        $container = new ContainerBuilder();
        $container->register(CyclomaticComplexityCollector::class)
            ->addTag(CollectorCompilerPass::TAG);

        $pass = new ParallelCollectorClassesCompilerPass();
        $pass->process($container);

        self::assertFalse($container->hasDefinition(StrategySelector::class));
    }

    #[Test]
    public function setsEmptyArraysWhenNoTaggedServices(): void
    {
        $container = new ContainerBuilder();
        $container->register(StrategySelector::class);

        $pass = new ParallelCollectorClassesCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(StrategySelector::class);

        self::assertSame([], $definition->getArgument('$collectorClasses'));
        self::assertSame([], $definition->getArgument('$derivedCollectorClasses'));
    }

    #[Test]
    public function extractsClassNameFromDefinitionWhenDifferentFromServiceId(): void
    {
        $container = new ContainerBuilder();
        $container->register(StrategySelector::class);

        // Register with an alias service ID but explicit class name
        $container->register('app.collector.ccn', CyclomaticComplexityCollector::class)
            ->addTag(CollectorCompilerPass::TAG);
        $container->register('app.collector.derived', MaintainabilityIndexCollector::class)
            ->addTag(CollectorCompilerPass::TAG_DERIVED);

        $pass = new ParallelCollectorClassesCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(StrategySelector::class);

        $collectorClasses = $definition->getArgument('$collectorClasses');
        self::assertCount(1, $collectorClasses);
        self::assertSame(CyclomaticComplexityCollector::class, $collectorClasses[0]);

        $derivedClasses = $definition->getArgument('$derivedCollectorClasses');
        self::assertCount(1, $derivedClasses);
        self::assertSame(MaintainabilityIndexCollector::class, $derivedClasses[0]);
    }
}
