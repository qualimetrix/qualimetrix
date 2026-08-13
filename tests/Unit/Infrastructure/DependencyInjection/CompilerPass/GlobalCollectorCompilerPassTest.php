<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\DependencyInjection\CompilerPass;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\MeasurementAggregationService;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\GlobalCollectorCompilerPass;
use Qualimetrix\Metrics\Coupling\CouplingCollector;
use Qualimetrix\Metrics\Structure\NocCollector;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(GlobalCollectorCompilerPass::class)]
final class GlobalCollectorCompilerPassTest extends TestCase
{
    private const string RUNNER_SERVICE_ID = 'qmx.measurement.aggregation';

    #[Test]
    public function collectsTaggedServicesIntoGlobalCollectorRunner(): void
    {
        $container = new ContainerBuilder();
        $container->register(self::RUNNER_SERVICE_ID, MeasurementAggregationService::class)
            ->setArgument('$collectors', []);
        $container->register(CouplingCollector::class)
            ->addTag(GlobalCollectorCompilerPass::TAG);
        $container->register(NocCollector::class)
            ->addTag(GlobalCollectorCompilerPass::TAG);

        $pass = new GlobalCollectorCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(self::RUNNER_SERVICE_ID);
        $collectors = $definition->getArgument('$collectors');

        self::assertCount(2, $collectors);
        self::assertInstanceOf(Reference::class, $collectors[0]);
        self::assertInstanceOf(Reference::class, $collectors[1]);
    }

    #[Test]
    public function doesNothingWhenRunnerNotRegistered(): void
    {
        $container = new ContainerBuilder();
        $container->register(CouplingCollector::class)
            ->addTag(GlobalCollectorCompilerPass::TAG);

        $pass = new GlobalCollectorCompilerPass();
        $pass->process($container);

        self::assertFalse($container->hasDefinition(self::RUNNER_SERVICE_ID));
    }

    #[Test]
    public function setsEmptyArrayWhenNoTaggedServices(): void
    {
        $container = new ContainerBuilder();
        $container->register(self::RUNNER_SERVICE_ID, MeasurementAggregationService::class)
            ->setArgument('$collectors', []);

        $pass = new GlobalCollectorCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(self::RUNNER_SERVICE_ID);

        self::assertSame([], $definition->getArgument('$collectors'));
    }
}
