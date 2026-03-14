<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Collector;

use AiMessDetector\Analysis\Collection\Metric\CompositeCollector;
use AiMessDetector\Core\Metric\DerivedCollectorInterface;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricCollectorInterface;
use ArrayIterator;
use Generator;
use LogicException;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use stdClass;

#[CoversClass(CompositeCollector::class)]
final class CompositeCollectorTest extends TestCase
{
    #[Test]
    public function itMergesMetricsFromAllCollectors(): void
    {
        $metrics1 = (new MetricBag())->with('loc', 100);
        $metrics2 = (new MetricBag())->with('ccn', 5);

        $collector1 = $this->createCollector('loc', $metrics1);
        $collector2 = $this->createCollector('ccn', $metrics2);

        $composite = new CompositeCollector([$collector1, $collector2]);
        $result = $composite->collect(new SplFileInfo(__FILE__), []);

        self::assertSame(100, $result->metrics->get('loc'));
        self::assertSame(5, $result->metrics->get('ccn'));
    }

    #[Test]
    public function itReturnsEmptyBagWithNoCollectors(): void
    {
        $composite = new CompositeCollector([]);
        $result = $composite->collect(new SplFileInfo(__FILE__), []);

        self::assertSame([], $result->metrics->all());
    }

    #[Test]
    public function itResetsAllCollectors(): void
    {
        $collector1 = $this->createMock(MetricCollectorInterface::class);
        $collector1->expects(self::once())->method('reset');
        $collector1->method('getVisitor')->willReturn(new class extends NodeVisitorAbstract {});

        $collector2 = $this->createMock(MetricCollectorInterface::class);
        $collector2->expects(self::once())->method('reset');
        $collector2->method('getVisitor')->willReturn(new class extends NodeVisitorAbstract {});

        $composite = new CompositeCollector([$collector1, $collector2]);
        $composite->reset();
    }

    #[Test]
    public function itExposesCollectors(): void
    {
        $collector1 = $this->createCollector('test1', new MetricBag());
        $collector2 = $this->createCollector('test2', new MetricBag());

        $composite = new CompositeCollector([$collector1, $collector2]);

        self::assertCount(2, $composite->getCollectors());
    }

    #[Test]
    public function itAcceptsIterableOfCollectors(): void
    {
        $metrics = (new MetricBag())->with('from_generator', 42);

        $collector = $this->createCollector('gen', $metrics);

        $generator = (static function () use ($collector): Generator {
            yield $collector;
        })();

        $composite = new CompositeCollector($generator);
        $result = $composite->collect(new SplFileInfo(__FILE__), []);

        self::assertSame(42, $result->metrics->get('from_generator'));
    }

    #[Test]
    public function itTraversesAstWithAllVisitors(): void
    {
        $callTracker = new stdClass();
        $callTracker->visitor1Called = false;
        $callTracker->visitor2Called = false;

        $visitor1 = new TrackingVisitor($callTracker, 'visitor1Called');
        $visitor2 = new TrackingVisitor($callTracker, 'visitor2Called');

        $collector1 = $this->createStub(MetricCollectorInterface::class);
        $collector1->method('getVisitor')->willReturn($visitor1);
        $collector1->method('collect')->willReturn(new MetricBag());

        $collector2 = $this->createStub(MetricCollectorInterface::class);
        $collector2->method('getVisitor')->willReturn($visitor2);
        $collector2->method('collect')->willReturn(new MetricBag());

        $composite = new CompositeCollector([$collector1, $collector2]);
        $composite->collect(new SplFileInfo(__FILE__), []);

        self::assertTrue($callTracker->visitor1Called);
        self::assertTrue($callTracker->visitor2Called);
    }

    #[Test]
    public function itHandlesSingleCollector(): void
    {
        $metrics = (new MetricBag())->with('single', 42);
        $collector = $this->createCollector('single', $metrics);

        $composite = new CompositeCollector([$collector]);
        $result = $composite->collect(new SplFileInfo(__FILE__), []);

        self::assertSame(42, $result->metrics->get('single'));
    }

    #[Test]
    public function itAppliesDerivedCollectors(): void
    {
        // Base collector provides ccn:App\Service\UserService::method
        $baseMetrics = (new MetricBag())
            ->with('ccn:App\Service\UserService::method', 5)
            ->with('loc:App\Service\UserService::method', 100);

        $baseCollector = $this->createCollector('base', $baseMetrics);

        // Derived collector that doubles the ccn value
        $derivedCollector = $this->createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('getName')->willReturn('derived');
        $derivedCollector->method('requires')->willReturn(['base']);
        $derivedCollector->method('provides')->willReturn(['derived_ccn']);
        $derivedCollector->method('getMetricDefinitions')->willReturn([]);

        // The derived collector receives a MetricBag with base metric names (without FQN)
        $derivedCollector->method('calculate')
            ->willReturnCallback(static function (MetricBag $sourceBag): MetricBag {
                $ccn = $sourceBag->get('ccn');
                if ($ccn === null) {
                    return new MetricBag();
                }

                return (new MetricBag())->with('derived_ccn', $ccn * 2);
            });

        $composite = new CompositeCollector([$baseCollector], [$derivedCollector]);
        $result = $composite->collect(new SplFileInfo(__FILE__), []);

        // Should have both base and derived metrics
        self::assertSame(5, $result->metrics->get('ccn:App\Service\UserService::method'));
        self::assertSame(100, $result->metrics->get('loc:App\Service\UserService::method'));
        self::assertSame(10, $result->metrics->get('derived_ccn:App\Service\UserService::method'));
    }

    #[Test]
    public function itAppliesMultipleDerivedCollectors(): void
    {
        $baseMetrics = (new MetricBag())
            ->with('value:test', 10);

        $baseCollector = $this->createCollector('base', $baseMetrics);

        // First derived collector
        $derived1 = $this->createStub(DerivedCollectorInterface::class);
        $derived1->method('getName')->willReturn('derived1');
        $derived1->method('requires')->willReturn(['base']);
        $derived1->method('provides')->willReturn(['doubled']);
        $derived1->method('getMetricDefinitions')->willReturn([]);
        $derived1->method('calculate')
            ->willReturnCallback(static fn(MetricBag $bag): MetricBag =>
                (new MetricBag())->with('doubled', ($bag->get('value') ?? 0) * 2));

        // Second derived collector
        $derived2 = $this->createStub(DerivedCollectorInterface::class);
        $derived2->method('getName')->willReturn('derived2');
        $derived2->method('requires')->willReturn(['base']);
        $derived2->method('provides')->willReturn(['tripled']);
        $derived2->method('getMetricDefinitions')->willReturn([]);
        $derived2->method('calculate')
            ->willReturnCallback(static fn(MetricBag $bag): MetricBag =>
                (new MetricBag())->with('tripled', ($bag->get('value') ?? 0) * 3));

        $composite = new CompositeCollector([$baseCollector], [$derived1, $derived2]);
        $result = $composite->collect(new SplFileInfo(__FILE__), []);

        self::assertSame(10, $result->metrics->get('value:test'));
        self::assertSame(20, $result->metrics->get('doubled:test'));
        self::assertSame(30, $result->metrics->get('tripled:test'));
    }

    #[Test]
    public function itHandlesEmptyDerivedCollectors(): void
    {
        $metrics = (new MetricBag())->with('base', 42);
        $collector = $this->createCollector('base', $metrics);

        // Empty derived collectors array
        $composite = new CompositeCollector([$collector], []);
        $result = $composite->collect(new SplFileInfo(__FILE__), []);

        self::assertSame(42, $result->metrics->get('base'));
    }

    #[Test]
    public function itHandlesDerivedCollectorWithNoMetrics(): void
    {
        $baseMetrics = (new MetricBag())
            ->with('key:fqn', 10);

        $baseCollector = $this->createCollector('base', $baseMetrics);

        // Derived collector that returns empty bag
        $derivedCollector = $this->createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('getName')->willReturn('empty_derived');
        $derivedCollector->method('requires')->willReturn(['base']);
        $derivedCollector->method('provides')->willReturn([]);
        $derivedCollector->method('getMetricDefinitions')->willReturn([]);
        $derivedCollector->method('calculate')->willReturn(new MetricBag());

        $composite = new CompositeCollector([$baseCollector], [$derivedCollector]);
        $result = $composite->collect(new SplFileInfo(__FILE__), []);

        // Should only have base metrics
        self::assertSame(10, $result->metrics->get('key:fqn'));
    }

    #[Test]
    public function itHandlesMetricsWithoutColonSeparator(): void
    {
        // Base metrics without colon separator should be ignored by derived collectors
        $baseMetrics = (new MetricBag())
            ->with('no_separator', 5)
            ->with('with:separator', 10);

        $baseCollector = $this->createCollector('base', $baseMetrics);

        $derivedCollector = $this->createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('getName')->willReturn('derived');
        $derivedCollector->method('requires')->willReturn(['base']);
        $derivedCollector->method('provides')->willReturn(['calculated']);
        $derivedCollector->method('getMetricDefinitions')->willReturn([]);
        $derivedCollector->method('calculate')
            ->willReturnCallback(static function (MetricBag $sourceBag): MetricBag {
                // Should only receive metrics with separator
                $value = $sourceBag->get('with');

                return $value !== null
                    ? (new MetricBag())->with('calculated', $value * 2)
                    : new MetricBag();
            });

        $composite = new CompositeCollector([$baseCollector], [$derivedCollector]);
        $result = $composite->collect(new SplFileInfo(__FILE__), []);

        self::assertSame(5, $result->metrics->get('no_separator'));
        self::assertSame(10, $result->metrics->get('with:separator'));
        self::assertSame(20, $result->metrics->get('calculated:separator'));
    }

    #[Test]
    public function itExposesDerivedCollectors(): void
    {
        $collector = $this->createCollector('base', new MetricBag());

        $derivedCollector = $this->createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('getName')->willReturn('derived');

        $composite = new CompositeCollector([$collector], [$derivedCollector]);

        $derivedCollectors = $composite->getDerivedCollectors();

        self::assertCount(1, $derivedCollectors);
        self::assertSame($derivedCollector, $derivedCollectors[0]);
    }

    #[Test]
    public function itAcceptsIterableOfDerivedCollectors(): void
    {
        $collector = $this->createCollector('base', new MetricBag());

        $derivedCollector = $this->createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('getName')->willReturn('derived');
        $derivedCollector->method('requires')->willReturn([]);
        $derivedCollector->method('provides')->willReturn([]);
        $derivedCollector->method('getMetricDefinitions')->willReturn([]);

        // Use generator for derived collectors
        $generator = (static function () use ($derivedCollector): Generator {
            yield $derivedCollector;
        })();

        $composite = new CompositeCollector([$collector], $generator);

        self::assertCount(1, $composite->getDerivedCollectors());
    }

    #[Test]
    public function itHandlesMultipleFqnsInDerivedCollector(): void
    {
        // Multiple symbols with metrics
        $baseMetrics = (new MetricBag())
            ->with('ccn:App\Service\A::method1', 5)
            ->with('ccn:App\Service\B::method2', 8)
            ->with('loc:App\Service\A::method1', 100)
            ->with('loc:App\Service\B::method2', 200);

        $baseCollector = $this->createCollector('base', $baseMetrics);

        $derivedCollector = $this->createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('getName')->willReturn('derived');
        $derivedCollector->method('requires')->willReturn(['base']);
        $derivedCollector->method('provides')->willReturn(['ratio']);
        $derivedCollector->method('getMetricDefinitions')->willReturn([]);
        $derivedCollector->method('calculate')
            ->willReturnCallback(static function (MetricBag $sourceBag): MetricBag {
                $ccn = $sourceBag->get('ccn');
                $loc = $sourceBag->get('loc');

                if ($ccn === null || $loc === null) {
                    return new MetricBag();
                }

                return (new MetricBag())->with('ratio', $ccn / $loc);
            });

        $composite = new CompositeCollector([$baseCollector], [$derivedCollector]);
        $result = $composite->collect(new SplFileInfo(__FILE__), []);

        // Should have derived metrics for each FQN
        self::assertEqualsWithDelta(0.05, $result->metrics->get('ratio:App\Service\A::method1'), 0.001);
        self::assertEqualsWithDelta(0.04, $result->metrics->get('ratio:App\Service\B::method2'), 0.001);
    }

    #[Test]
    public function itConvertsDifferentIterableTypes(): void
    {
        $metrics = (new MetricBag())->with('test', 1);
        $collector = $this->createCollector('test', $metrics);

        // Test with array
        $composite1 = new CompositeCollector([$collector]);
        self::assertCount(1, $composite1->getCollectors());

        // Test with ArrayIterator
        $composite2 = new CompositeCollector(new ArrayIterator([$collector]));
        self::assertCount(1, $composite2->getCollectors());

        // Test with Generator
        $generator = (static function () use ($collector): Generator {
            yield $collector;
        })();
        $composite3 = new CompositeCollector($generator);
        self::assertCount(1, $composite3->getCollectors());
    }

    #[Test]
    public function itMergesDuplicateMetricKeys(): void
    {
        // Both collectors return the same metric key (merge behavior)
        $metrics1 = (new MetricBag())->with('duplicate', 10);
        $metrics2 = (new MetricBag())->with('duplicate', 20); // Will override

        $collector1 = $this->createCollector('c1', $metrics1);
        $collector2 = $this->createCollector('c2', $metrics2);

        $composite = new CompositeCollector([$collector1, $collector2]);
        $result = $composite->collect(new SplFileInfo(__FILE__), []);

        // Second value should override first (MetricBag::merge behavior)
        self::assertSame(20, $result->metrics->get('duplicate'));
    }

    #[Test]
    public function itThrowsOnCyclicDerivedCollectorDependencies(): void
    {
        $baseMetrics = (new MetricBag())->with('value:test', 10);
        $baseCollector = $this->createCollector('base', $baseMetrics);

        // Collector A requires B, Collector B requires A => cycle
        $derivedA = $this->createStub(DerivedCollectorInterface::class);
        $derivedA->method('getName')->willReturn('derivedA');
        $derivedA->method('requires')->willReturn(['derivedB']);
        $derivedA->method('provides')->willReturn(['metricA']);
        $derivedA->method('getMetricDefinitions')->willReturn([]);
        $derivedA->method('calculate')->willReturn(new MetricBag());

        $derivedB = $this->createStub(DerivedCollectorInterface::class);
        $derivedB->method('getName')->willReturn('derivedB');
        $derivedB->method('requires')->willReturn(['derivedA']);
        $derivedB->method('provides')->willReturn(['metricB']);
        $derivedB->method('getMetricDefinitions')->willReturn([]);
        $derivedB->method('calculate')->willReturn(new MetricBag());

        $composite = new CompositeCollector([$baseCollector], [$derivedA, $derivedB]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Cyclic dependency.*derivedA.*derivedB|Cyclic dependency.*derivedB.*derivedA/');

        $composite->collect(new SplFileInfo(__FILE__), []);
    }

    private function createCollector(string $name, MetricBag $metrics): MetricCollectorInterface
    {
        $collector = $this->createStub(MetricCollectorInterface::class);
        $collector->method('getName')->willReturn($name);
        $collector->method('getVisitor')->willReturn(new class extends NodeVisitorAbstract {});
        $collector->method('collect')->willReturn($metrics);

        return $collector;
    }
}

/**
 * @internal
 */
final class TrackingVisitor extends NodeVisitorAbstract
{
    public function __construct(
        private readonly stdClass $tracker,
        private readonly string $property,
    ) {}

    /**
     * @param Node[] $nodes
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->tracker->{$this->property} = true;

        return null;
    }
}
