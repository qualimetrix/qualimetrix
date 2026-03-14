<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Aggregator;

use AiMessDetector\Analysis\Aggregator\GlobalCollectorRunner;
use AiMessDetector\Core\Dependency\DependencyGraphInterface;
use AiMessDetector\Core\Metric\GlobalContextCollectorInterface;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlobalCollectorRunner::class)]
final class GlobalCollectorRunnerTest extends TestCase
{
    #[Test]
    public function itRunsCollectorsInTopologicalOrder(): void
    {
        $executionOrder = [];

        $collector1 = $this->createCollector('collector1', [], ['metric1']);
        $collector1->expects(self::once())
            ->method('calculate')
            ->willReturnCallback(function () use (&$executionOrder): void {
                $executionOrder[] = 'collector1';
            });

        $collector2 = $this->createCollector('collector2', ['metric1'], ['metric2']);
        $collector2->expects(self::once())
            ->method('calculate')
            ->willReturnCallback(function () use (&$executionOrder): void {
                $executionOrder[] = 'collector2';
            });

        // Pass collectors in reverse order to verify sorting works
        $runner = new GlobalCollectorRunner([$collector2, $collector1]);

        $graph = $this->createStub(DependencyGraphInterface::class);
        $repository = $this->createStub(MetricRepositoryInterface::class);

        $runner->run($graph, $repository);

        // Collector1 should run before collector2 (collector2 depends on collector1's metric)
        self::assertSame(['collector1', 'collector2'], $executionOrder);
    }

    #[Test]
    public function itHandlesEmptyCollectorList(): void
    {
        $runner = new GlobalCollectorRunner([]);

        self::assertSame(0, $runner->count());
        self::assertFalse($runner->hasCollectors());

        $graph = $this->createStub(DependencyGraphInterface::class);
        $repository = $this->createStub(MetricRepositoryInterface::class);

        // Should not throw
        $runner->run($graph, $repository);
    }

    #[Test]
    public function itReportsCorrectCollectorCount(): void
    {
        $collector1 = $this->createCollectorStub('collector1', [], ['metric1']);
        $collector2 = $this->createCollectorStub('collector2', [], ['metric2']);

        $runner = new GlobalCollectorRunner([$collector1, $collector2]);

        self::assertSame(2, $runner->count());
        self::assertTrue($runner->hasCollectors());
    }

    #[Test]
    public function itRunsIndependentCollectorsInAnyOrder(): void
    {
        $runCount = 0;

        $collector1 = $this->createCollector('collector1', [], ['metric1']);
        $collector1->expects(self::once())
            ->method('calculate')
            ->willReturnCallback(function () use (&$runCount): void {
                $runCount++;
            });

        $collector2 = $this->createCollector('collector2', [], ['metric2']);
        $collector2->expects(self::once())
            ->method('calculate')
            ->willReturnCallback(function () use (&$runCount): void {
                $runCount++;
            });

        $runner = new GlobalCollectorRunner([$collector1, $collector2]);

        $graph = $this->createStub(DependencyGraphInterface::class);
        $repository = $this->createStub(MetricRepositoryInterface::class);

        $runner->run($graph, $repository);

        self::assertSame(2, $runCount);
    }

    /**
     * @param list<string> $requires
     * @param list<string> $provides
     */
    private function createCollector(
        string $name,
        array $requires,
        array $provides,
    ): GlobalContextCollectorInterface&MockObject {
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
    private function createCollectorStub(
        string $name,
        array $requires,
        array $provides,
    ): GlobalContextCollectorInterface&Stub {
        $collector = $this->createStub(GlobalContextCollectorInterface::class);
        $collector->method('getName')->willReturn($name);
        $collector->method('requires')->willReturn($requires);
        $collector->method('provides')->willReturn($provides);
        $collector->method('getMetricDefinitions')->willReturn([]);

        return $collector;
    }
}
