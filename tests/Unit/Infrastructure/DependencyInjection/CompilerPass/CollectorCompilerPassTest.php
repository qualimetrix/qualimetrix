<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\DependencyInjection\CompilerPass;

use AiMessDetector\Analysis\Collection\Metric\CompositeCollector;
use AiMessDetector\Infrastructure\DependencyInjection\CompilerPass\CollectorCompilerPass;
use AiMessDetector\Metrics\Complexity\CyclomaticComplexityCollector;
use AiMessDetector\Metrics\Complexity\NpathComplexityCollector;
use AiMessDetector\Metrics\Maintainability\MaintainabilityIndexCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(CollectorCompilerPass::class)]
final class CollectorCompilerPassTest extends TestCase
{
    #[Test]
    public function collectsTaggedServicesIntoCompositeCollector(): void
    {
        $container = new ContainerBuilder();
        $container->register(CompositeCollector::class);
        $container->register(CyclomaticComplexityCollector::class)
            ->addTag(CollectorCompilerPass::TAG);
        $container->register(NpathComplexityCollector::class)
            ->addTag(CollectorCompilerPass::TAG);
        $container->register(MaintainabilityIndexCollector::class)
            ->addTag(CollectorCompilerPass::TAG_DERIVED);

        $pass = new CollectorCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(CompositeCollector::class);

        $collectors = $definition->getArgument(0);
        self::assertCount(2, $collectors);
        self::assertInstanceOf(Reference::class, $collectors[0]);
        self::assertInstanceOf(Reference::class, $collectors[1]);

        $derivedCollectors = $definition->getArgument(1);
        self::assertCount(1, $derivedCollectors);
        self::assertInstanceOf(Reference::class, $derivedCollectors[0]);
    }

    #[Test]
    public function doesNothingWhenCompositeCollectorNotRegistered(): void
    {
        $container = new ContainerBuilder();
        $container->register(CyclomaticComplexityCollector::class)
            ->addTag(CollectorCompilerPass::TAG);

        $pass = new CollectorCompilerPass();
        $pass->process($container);

        self::assertFalse($container->hasDefinition(CompositeCollector::class));
    }

    #[Test]
    public function setsEmptyArraysWhenNoTaggedServices(): void
    {
        $container = new ContainerBuilder();
        $container->register(CompositeCollector::class);

        $pass = new CollectorCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(CompositeCollector::class);

        self::assertSame([], $definition->getArgument(0));
        self::assertSame([], $definition->getArgument(1));
    }
}
