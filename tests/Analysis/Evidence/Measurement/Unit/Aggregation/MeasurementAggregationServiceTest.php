<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Unit\Aggregation;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\MeasurementAggregationService;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\FileMeasurementCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\GlobalContextCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReach;
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

    #[Test]
    public function itDerivesReachFromTheSameRegularDerivedAndGlobalCollectors(): void
    {
        $regular = self::createStub(MetricCollectorInterface::class);
        $regular->method('provides')->willReturn([MetricName::COMPLEXITY_CCN, MetricName::COUPLING_INSTABILITY]);
        $regular->method('getMetricDefinitions')->willReturn([]);
        $derived = self::createStub(DerivedCollectorInterface::class);
        $derived->method('provides')->willReturn([MetricName::MAINTAINABILITY_MI]);
        $derived->method('getMetricDefinitions')->willReturn([]);
        $fileCollector = self::createMock(FileMeasurementCollectorInterface::class);
        $fileCollector->expects(self::once())->method('getCollectors')->willReturn([$regular]);
        $fileCollector->expects(self::once())->method('getDerivedCollectors')->willReturn([$derived]);
        $global = self::createStub(GlobalContextCollectorInterface::class);
        $global->method('getName')->willReturn('global');
        $global->method('requires')->willReturn([MetricName::agg(MetricName::COMPLEXITY_CCN, AggregationStrategy::Max)]);
        $global->method('provides')->willReturn([MetricName::COUPLING_INSTABILITY]);
        $global->method('getMetricDefinitions')->willReturn([]);

        $catalog = new MeasurementAggregationService([$global], $fileCollector, self::createStub(ProfilerInterface::class));

        self::assertSame(MetricReach::Members, $catalog->metricReach(MetricName::COMPLEXITY_CCN));
        self::assertSame(MetricReach::Members, $catalog->metricReach(MetricName::agg(MetricName::COMPLEXITY_CCN, AggregationStrategy::Max)));
        self::assertSame(MetricReach::Members, $catalog->metricReach(MetricName::MAINTAINABILITY_MI));
        self::assertSame(MetricReach::Run, $catalog->metricReach(MetricName::COUPLING_INSTABILITY));
        self::assertSame(MetricReach::Run, $catalog->metricReach(MetricName::agg(MetricName::COUPLING_INSTABILITY, AggregationStrategy::Average)));
    }

    #[Test]
    public function itDeclaresAggregationOwnedSymbolPopulationsAsMemberEvidence(): void
    {
        $catalog = new MeasurementAggregationService([], new CompositeCollector([], new DeclarationRegistrarFactory()), self::createStub(ProfilerInterface::class));

        foreach ([MetricName::SIZE_SYMBOL_METHOD_COUNT, MetricName::SIZE_SYMBOL_CLASS_COUNT, MetricName::SIZE_SYMBOL_DECLARING_NAMESPACE_COUNT, MetricName::COMPLEXITY_WMC] as $key) {
            self::assertSame(MetricReach::Members, $catalog->metricReach($key));
        }

        $regular = self::createStub(MetricCollectorInterface::class);
        $regular->method('provides')->willReturn([MetricName::COMPLEXITY_CCN]);
        $regular->method('getMetricDefinitions')->willReturn([]);
        $catalog = new MeasurementAggregationService([], new CompositeCollector([$regular], new DeclarationRegistrarFactory()), self::createStub(ProfilerInterface::class));

        foreach ([AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max, AggregationStrategy::Percentile95, AggregationStrategy::Count] as $strategy) {
            self::assertSame(MetricReach::Members, $catalog->metricReach(MetricName::agg(MetricName::COMPLEXITY_CCN, $strategy)));
        }
    }

    #[Test]
    public function itRefusesAnUnknownMeasuredMetricInsteadOfInventingMemberReach(): void
    {
        $catalog = new MeasurementAggregationService([], new CompositeCollector([], new DeclarationRegistrarFactory()), self::createStub(ProfilerInterface::class));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unknown measured metric "missing.metric.max"');
        $catalog->metricReach('missing.metric.max');
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

}
