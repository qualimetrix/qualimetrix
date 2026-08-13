<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\DependencyInjection\CompilerPass;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\FileMeasurement\CompositeCollector;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\CollectorCompilerPass;
use Qualimetrix\Metrics\Complexity\CyclomaticComplexityCollector;
use Qualimetrix\Metrics\Complexity\NpathComplexityCollector;
use Qualimetrix\Metrics\Maintainability\MaintainabilityIndexCollector;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(CollectorCompilerPass::class)]
final class CollectorCompilerPassTest extends TestCase
{
    private const string COLLECTOR_SERVICE_ID = 'qmx.measurement.file_collector';

    #[Test]
    public function collectsTaggedServicesIntoCompositeCollector(): void
    {
        $container = new ContainerBuilder();
        $container->register(self::COLLECTOR_SERVICE_ID, CompositeCollector::class)
            ->setArguments(['$collectors' => [], '$derivedCollectors' => []]);
        $container->register(CyclomaticComplexityCollector::class)
            ->addTag(CollectorCompilerPass::TAG);
        $container->register(NpathComplexityCollector::class)
            ->addTag(CollectorCompilerPass::TAG);
        $container->register(MaintainabilityIndexCollector::class)
            ->addTag(CollectorCompilerPass::TAG_DERIVED);

        $pass = new CollectorCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(self::COLLECTOR_SERVICE_ID);

        $collectors = $definition->getArgument('$collectors');
        self::assertCount(2, $collectors);
        self::assertInstanceOf(Reference::class, $collectors[0]);
        self::assertInstanceOf(Reference::class, $collectors[1]);

        $derivedCollectors = $definition->getArgument('$derivedCollectors');
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

        self::assertFalse($container->hasDefinition(self::COLLECTOR_SERVICE_ID));
    }

    #[Test]
    public function setsEmptyArraysWhenNoTaggedServices(): void
    {
        $container = new ContainerBuilder();
        $container->register(self::COLLECTOR_SERVICE_ID, CompositeCollector::class)
            ->setArguments(['$collectors' => [], '$derivedCollectors' => []]);

        $pass = new CollectorCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(self::COLLECTOR_SERVICE_ID);

        self::assertSame([], $definition->getArgument('$collectors'));
        self::assertSame([], $definition->getArgument('$derivedCollectors'));
    }
}
