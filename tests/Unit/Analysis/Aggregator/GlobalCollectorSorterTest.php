<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Aggregator;

use AiMessDetector\Analysis\Aggregator\GlobalCollectorSorter;
use AiMessDetector\Analysis\Exception\CyclicDependencyException;
use AiMessDetector\Core\Metric\GlobalContextCollectorInterface;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlobalCollectorSorter::class)]
#[CoversClass(CyclicDependencyException::class)]
final class GlobalCollectorSorterTest extends TestCase
{
    private GlobalCollectorSorter $sorter;

    protected function setUp(): void
    {
        $this->sorter = new GlobalCollectorSorter();
    }

    #[Test]
    public function itReturnsEmptyArrayForEmptyInput(): void
    {
        $result = $this->sorter->sort([]);

        self::assertSame([], $result);
    }

    #[Test]
    public function itReturnsSingleCollectorUnchanged(): void
    {
        $collector = $this->createCollector('single', [], ['metric']);

        $result = $this->sorter->sort([$collector]);

        self::assertCount(1, $result);
        self::assertSame('single', $result[0]->getName());
    }

    #[Test]
    public function itHandlesCollectorsWithNoDependencies(): void
    {
        $a = $this->createCollector('a', [], ['metricA']);
        $b = $this->createCollector('b', [], ['metricB']);
        $c = $this->createCollector('c', [], ['metricC']);

        $result = $this->sorter->sort([$a, $b, $c]);

        self::assertCount(3, $result);
        // All should be present (order doesn't matter for independent collectors)
        $names = array_map(fn($c) => $c->getName(), $result);
        self::assertContains('a', $names);
        self::assertContains('b', $names);
        self::assertContains('c', $names);
    }

    #[Test]
    public function itSortsLinearDependencyChain(): void
    {
        // C depends on B, B depends on A
        $a = $this->createCollector('a', [], ['metricA']);
        $b = $this->createCollector('b', ['metricA'], ['metricB']);
        $c = $this->createCollector('c', ['metricB'], ['metricC']);

        $result = $this->sorter->sort([$c, $b, $a]); // Input in reverse order

        self::assertCount(3, $result);
        // Must be: A before B before C
        $names = array_map(fn($c) => $c->getName(), $result);
        self::assertSame(['a', 'b', 'c'], $names);
    }

    #[Test]
    public function itSortsDiamondDependency(): void
    {
        // D depends on B and C, both depend on A
        //     A
        //    / \
        //   B   C
        //    \ /
        //     D
        $a = $this->createCollector('a', [], ['metricA']);
        $b = $this->createCollector('b', ['metricA'], ['metricB']);
        $c = $this->createCollector('c', ['metricA'], ['metricC']);
        $d = $this->createCollector('d', ['metricB', 'metricC'], ['metricD']);

        $result = $this->sorter->sort([$d, $c, $b, $a]);

        $names = array_map(fn($c) => $c->getName(), $result);

        // A must come first
        self::assertSame('a', $names[0]);
        // D must come last
        self::assertSame('d', $names[3]);
        // B and C can be in any order between A and D
        self::assertContains('b', \array_slice($names, 1, 2));
        self::assertContains('c', \array_slice($names, 1, 2));
    }

    #[Test]
    public function itHandlesDottedMetricNames(): void
    {
        // Similar to real-world: abstractness requires classCount.sum from classCount provider
        $classCount = $this->createCollector('classCount', [], ['classCount']);
        $abstractness = $this->createCollector('abstractness', ['classCount.sum'], ['abstractness']);

        $result = $this->sorter->sort([$abstractness, $classCount]);

        $names = array_map(fn($c) => $c->getName(), $result);
        self::assertSame(['classCount', 'abstractness'], $names);
    }

    #[Test]
    public function itSortsRealWorldCouplingCollectors(): void
    {
        // Real-world scenario:
        // - coupling: provides ca, ce, instability, requires nothing
        // - abstractness: provides abstractness, requires classCount.sum, etc.
        // - distance: provides distance, requires instability, abstractness

        $coupling = $this->createCollector('coupling', [], ['ca', 'ce', 'instability']);
        $abstractness = $this->createCollector(
            'abstractness',
            ['classCount.sum', 'abstractClassCount.sum', 'interfaceCount.sum'],
            ['abstractness'],
        );
        $distance = $this->createCollector('distance', ['instability', 'abstractness'], ['distance']);

        $result = $this->sorter->sort([$distance, $abstractness, $coupling]);

        $names = array_map(fn($c) => $c->getName(), $result);

        // coupling and abstractness have no mutual dependency, can be in any order
        // but both must come before distance
        $distanceIndex = array_search('distance', $names, true);
        $couplingIndex = array_search('coupling', $names, true);
        $abstractnessIndex = array_search('abstractness', $names, true);

        self::assertLessThan($distanceIndex, $couplingIndex);
        self::assertLessThan($distanceIndex, $abstractnessIndex);
    }

    #[Test]
    public function itDetectsSimpleCycle(): void
    {
        // A depends on B, B depends on A
        $a = $this->createCollector('a', ['metricB'], ['metricA']);
        $b = $this->createCollector('b', ['metricA'], ['metricB']);

        $this->expectException(CyclicDependencyException::class);
        $this->expectExceptionMessage('Cyclic dependency detected');

        $this->sorter->sort([$a, $b]);
    }

    #[Test]
    public function itDetectsLongCycle(): void
    {
        // A → B → C → A
        $a = $this->createCollector('a', ['metricC'], ['metricA']);
        $b = $this->createCollector('b', ['metricA'], ['metricB']);
        $c = $this->createCollector('c', ['metricB'], ['metricC']);

        $this->expectException(CyclicDependencyException::class);

        $this->sorter->sort([$a, $b, $c]);
    }

    #[Test]
    public function itIncludesCycleInException(): void
    {
        $a = $this->createCollector('a', ['metricB'], ['metricA']);
        $b = $this->createCollector('b', ['metricA'], ['metricB']);

        try {
            $this->sorter->sort([$a, $b]);
            self::fail('Expected CyclicDependencyException');
        } catch (CyclicDependencyException $e) {
            self::assertContains('a', $e->cycle);
            self::assertContains('b', $e->cycle);
        }
    }

    #[Test]
    public function itAcceptsIterableInput(): void
    {
        $a = $this->createCollector('a', [], ['metricA']);
        $b = $this->createCollector('b', ['metricA'], ['metricB']);

        $generator = (static function () use ($a, $b): Generator {
            yield $b;
            yield $a;
        })();

        $result = $this->sorter->sort($generator);

        $names = array_map(fn($c) => $c->getName(), $result);
        self::assertSame(['a', 'b'], $names);
    }

    #[Test]
    public function itHandlesUnknownRequiredMetrics(): void
    {
        // Collector requires a metric that no one provides - should not fail
        // (the metric might come from file-level collectors)
        $a = $this->createCollector('a', ['unknownMetric'], ['metricA']);

        $result = $this->sorter->sort([$a]);

        self::assertCount(1, $result);
        self::assertSame('a', $result[0]->getName());
    }

    /**
     * Creates a mock GlobalContextCollectorInterface.
     *
     * @param list<string> $requires
     * @param list<string> $provides
     */
    private function createCollector(string $name, array $requires, array $provides): GlobalContextCollectorInterface
    {
        $collector = $this->createStub(GlobalContextCollectorInterface::class);
        $collector->method('getName')->willReturn($name);
        $collector->method('requires')->willReturn($requires);
        $collector->method('provides')->willReturn($provides);

        return $collector;
    }
}
