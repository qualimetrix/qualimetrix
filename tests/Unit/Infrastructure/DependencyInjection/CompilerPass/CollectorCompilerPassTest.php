<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\DependencyInjection\CompilerPass;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Collection\Metric\CompositeCollector;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\CollectorCompilerPass;
use Qualimetrix\Metrics\Complexity\CyclomaticComplexityCollector;
use Qualimetrix\Metrics\Complexity\NpathComplexityCollector;
use Qualimetrix\Metrics\Maintainability\MaintainabilityIndexCollector;
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
