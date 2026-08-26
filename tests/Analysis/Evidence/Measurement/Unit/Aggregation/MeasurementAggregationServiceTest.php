<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Unit\Aggregation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\MeasurementAggregationService;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\FileMeasurementCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\GlobalContextCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\FileMeasurement\CompositeCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\SymbolLevel;

#[CoversClass(MeasurementAggregationService::class)]
final class MeasurementAggregationServiceTest extends TestCase
{
    #[Test]
    public function itPreservesAggregationGlobalAndReaggregationSpansAndLogOrder(): void
    {
        $events = [];
        $profiler = self::createStub(ProfilerInterface::class);
        $profiler->method('start')->willReturnCallback(
            static function (string $name) use (&$events): void {
                $events[] = 'start:' . $name;
            },
        );
        $profiler->method('stop')->willReturnCallback(
            static function (string $name) use (&$events): void {
                $events[] = 'stop:' . $name;
            },
        );
        $logger = self::createStub(LoggerInterface::class);
        $logger->method('debug')->willReturnCallback(
            static function (string $message) use (&$events): void {
                $events[] = 'debug:' . $message;
            },
        );
        $logger->method('info')->willReturnCallback(
            static function (string $message) use (&$events): void {
                $events[] = 'info:' . $message;
            },
        );

        $collector = self::createStub(GlobalContextCollectorInterface::class);
        $collector->method('getName')->willReturn('global');
        $collector->method('requires')->willReturn([]);
        $collector->method('provides')->willReturn(['global']);
        $collector->method('getMetricDefinitions')->willReturn([
            new MetricDefinition('global', SymbolLevel::Class_, [
                SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
            ]),
        ]);
        $collector->method('calculate')->willReturnCallback(
            static function () use (&$events): void {
                $events[] = 'calculate:global';
            },
        );

        $fileCollector = self::createStub(FileMeasurementCollectorInterface::class);
        $fileCollector->method('getCollectors')->willReturn([]);
        $fileCollector->method('getDerivedCollectors')->willReturn([]);

        (new MeasurementAggregationService([$collector], $fileCollector, $profiler, $logger))
            ->aggregate(new InMemoryMetricRepository(), self::createStub(DependencyGraphInterface::class));

        self::assertSame([
            'debug:Starting aggregation phase',
            'start:aggregation',
            'stop:aggregation',
            'info:Aggregation completed',
            'debug:Running global collectors',
            'start:global',
            'calculate:global',
            'stop:global',
            'start:aggregation.global',
            'stop:aggregation.global',
        ], array_values(array_filter(
            $events,
            static fn(string $event): bool => \in_array($event, [
                'debug:Starting aggregation phase',
                'start:aggregation',
                'stop:aggregation',
                'info:Aggregation completed',
                'debug:Running global collectors',
                'start:global',
                'calculate:global',
                'stop:global',
                'start:aggregation.global',
                'stop:aggregation.global',
            ], true),
        )));
    }

    #[Test]
    public function itRunsCollectorsInTopologicalOrder(): void
    {
        $executionOrder = [];
        $collector1 = $this->createCollector('collector1', [], ['metric1']);
        $collector1->expects(self::once())->method('calculate')->willReturnCallback(
            function () use (&$executionOrder): void {
                $executionOrder[] = 'collector1';
            },
        );
        $collector2 = $this->createCollector('collector2', ['metric1'], ['metric2']);
        $collector2->expects(self::once())->method('calculate')->willReturnCallback(
            function () use (&$executionOrder): void {
                $executionOrder[] = 'collector2';
            },
        );

        $namespaceTree = (new MeasurementAggregationService(
            [$collector2, $collector1],
            new CompositeCollector([], new DeclarationRegistrarFactory()),
            self::createStub(ProfilerInterface::class),
        ))
            ->aggregate(new InMemoryMetricRepository(), self::createStub(DependencyGraphInterface::class));

        self::assertSame([], $namespaceTree->getAllNamespaces());
        self::assertSame(['collector1', 'collector2'], $executionOrder);
    }

    #[Test]
    public function itHandlesEmptyCollectorList(): void
    {
        $events = [];
        $profiler = self::createStub(ProfilerInterface::class);
        $profiler->method('start')->willReturnCallback(static function (string $name) use (&$events): void {
            $events[] = 'start:' . $name;
        });
        $profiler->method('stop')->willReturnCallback(static function (string $name) use (&$events): void {
            $events[] = 'stop:' . $name;
        });
        $namespaceTree = (new MeasurementAggregationService([], new CompositeCollector([], new DeclarationRegistrarFactory()), $profiler))
            ->aggregate(new InMemoryMetricRepository(), self::createStub(DependencyGraphInterface::class));

        self::assertSame([], $namespaceTree->getAllNamespaces());
        self::assertSame([
            'start:aggregation',
            'stop:aggregation',
            'start:global',
            'stop:global',
        ], $events);
    }

    #[Test]
    public function itRunsEveryConfiguredCollector(): void
    {
        $collector1 = $this->createCollectorStub('collector1', [], ['metric1']);
        $collector2 = $this->createCollectorStub('collector2', [], ['metric2']);
        $calls = 0;
        $collector1->method('calculate')->willReturnCallback(static function () use (&$calls): void {
            $calls++;
        });
        $collector2->method('calculate')->willReturnCallback(static function () use (&$calls): void {
            $calls++;
        });

        (new MeasurementAggregationService(
            [$collector1, $collector2],
            new CompositeCollector([], new DeclarationRegistrarFactory()),
            self::createStub(ProfilerInterface::class),
        ))
            ->aggregate(new InMemoryMetricRepository(), self::createStub(DependencyGraphInterface::class));

        self::assertSame(2, $calls);
    }

    #[Test]
    public function itRunsIndependentCollectorsInAnyOrder(): void
    {
        $runCount = 0;
        $collector1 = $this->createCollector('collector1', [], ['metric1']);
        $collector1->expects(self::once())->method('calculate')->willReturnCallback(
            function () use (&$runCount): void {
                $runCount++;
            },
        );
        $collector2 = $this->createCollector('collector2', [], ['metric2']);
        $collector2->expects(self::once())->method('calculate')->willReturnCallback(
            function () use (&$runCount): void {
                $runCount++;
            },
        );

        (new MeasurementAggregationService(
            [$collector1, $collector2],
            new CompositeCollector([], new DeclarationRegistrarFactory()),
            self::createStub(ProfilerInterface::class),
        ))
            ->aggregate(new InMemoryMetricRepository(), self::createStub(DependencyGraphInterface::class));

        self::assertSame(2, $runCount);
    }

    /**
     * @param list<string> $requires
     * @param list<string> $provides
     */
    private function createCollector(string $name, array $requires, array $provides): GlobalContextCollectorInterface&MockObject
    {
        $collector = $this->createMock(GlobalContextCollectorInterface::class);
        $collector->method('getName')->willReturn($name);
        $collector->method('requires')->willReturn($requires);
        $collector->method('provides')->willReturn($provides);
        $collector->method('getMetricDefinitions')->willReturn([]);

        return $collector;
    }

    /**
     * @param list<string> $requires
     * @param list<string> $provides
     */
    private function createCollectorStub(string $name, array $requires, array $provides): GlobalContextCollectorInterface&Stub
    {
        $collector = self::createStub(GlobalContextCollectorInterface::class);
        $collector->method('getName')->willReturn($name);
        $collector->method('requires')->willReturn($requires);
        $collector->method('provides')->willReturn($provides);
        $collector->method('getMetricDefinitions')->willReturn([]);

        return $collector;
    }
}
