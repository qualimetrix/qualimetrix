<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Collector;

use PhpParser\NodeVisitorAbstract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Collection\Metric\CompositeCollector;
use Qualimetrix\Core\Metric\DerivedCollectorInterface;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricCollectorInterface;
use SplFileInfo;

/**
 * Regression test: CompositeCollector::applyDerivedCollectors() does not
 * topologically sort derived collectors based on requires()/provides().
 *
 * Unlike GlobalCollectorSorter (which sorts GlobalContextCollectors),
 * CompositeCollector iterates derived collectors in insertion order.
 * If collector A requires metrics from collector B but is registered first,
 * A will receive empty/missing input.
 *
 * @see GlobalCollectorSorter for the correct topological sort implementation
 */
#[CoversClass(CompositeCollector::class)]
#[Group('regression')]
final class DerivedCollectorSortTest extends TestCase
{
    #[Test]
    public function derivedCollectorsCannotSeeEachOthersOutputsEvenInCorrectOrder(): void
    {
        // BUG: Each derived collector receives only the BASE metrics (indexed by FQN),
        // not the accumulated result from previous derived collectors.
        // So even in "correct" order (B first, A second), A cannot see B's output.
        $baseCollector = $this->createBaseCollector(
            (new MetricBag())->with('raw:App\Service::method', 10),
        );

        $collectorB = $this->createDerivedCollector(
            name: 'collector-b',
            requires: [],
            provides: ['intermediate'],
            calculate: static fn(MetricBag $bag): MetricBag =>
                (new MetricBag())->with('intermediate', ($bag->get('raw') ?? 0) * 2),
        );

        $collectorA = $this->createDerivedCollector(
            name: 'collector-a',
            requires: ['collector-b'],
            provides: ['final'],
            calculate: static fn(MetricBag $bag): MetricBag =>
                (new MetricBag())->with('final', ($bag->get('intermediate') ?? 0) + 1),
        );

        // Even in correct order: B first, then A
        $composite = new CompositeCollector([$baseCollector], [$collectorB, $collectorA]);
        $result = $composite->collect(new SplFileInfo(__FILE__), []);

        self::assertSame(10, $result->metrics->get('raw:App\Service::method'));
        self::assertSame(20, $result->metrics->get('intermediate:App\Service::method'));

        // BUG: A receives only base metrics {raw: 10}, NOT {raw: 10, intermediate: 20}.
        // applyDerivedCollectors() passes $methodMetrics (from indexMetricsByFqn) to ALL
        // derived collectors, without updating it with results from previous collectors.
        // Expected: 21 (intermediate=20 + 1), Actual: 1 (intermediate=null -> 0 + 1)
        self::assertSame(
            21,
            $result->metrics->get('final:App\Service::method'),
            'BUG: Derived collector A cannot see output from B even in correct order. '
            . 'applyDerivedCollectors() passes only base metrics to each derived collector, '
            . 'not the accumulated result from previous ones.',
        );
    }

    #[Test]
    public function derivedCollectorsInWrongOrderShouldStillWork(): void
    {
        // BUG: When A (depends on B) is registered BEFORE B,
        // A runs first and gets empty 'intermediate' metric.
        $baseCollector = $this->createBaseCollector(
            (new MetricBag())->with('raw:App\Service::method', 10),
        );

        $collectorB = $this->createDerivedCollector(
            name: 'collector-b',
            requires: [],
            provides: ['intermediate'],
            calculate: static fn(MetricBag $bag): MetricBag =>
                (new MetricBag())->with('intermediate', ($bag->get('raw') ?? 0) * 2),
        );

        $collectorA = $this->createDerivedCollector(
            name: 'collector-a',
            requires: ['collector-b'],
            provides: ['final'],
            calculate: static fn(MetricBag $bag): MetricBag =>
                (new MetricBag())->with('final', ($bag->get('intermediate') ?? 0) + 1),
        );

        // Wrong order: A first, then B
        $composite = new CompositeCollector([$baseCollector], [$collectorA, $collectorB]);
        $result = $composite->collect(new SplFileInfo(__FILE__), []);

        // Base metric should always be correct
        self::assertSame(10, $result->metrics->get('raw:App\Service::method'));

        // B should compute correctly regardless of order
        self::assertSame(20, $result->metrics->get('intermediate:App\Service::method'));

        // BUG: A runs before B, so 'intermediate' is not yet in the FQN-indexed bag.
        // A receives a MetricBag with only 'raw' => 10, no 'intermediate'.
        // Result: final = 0 + 1 = 1 instead of expected 20 + 1 = 21
        self::assertSame(
            21,
            $result->metrics->get('final:App\Service::method'),
            'BUG: Derived collector A runs before B (no topological sort). '
            . 'A gets intermediate=null, computes final=1 instead of 21. '
            . 'CompositeCollector::applyDerivedCollectors() should sort by requires()/provides() '
            . 'like GlobalCollectorSorter does for global collectors.',
        );
    }

    #[Test]
    public function threeCollectorChainInReverseOrder(): void
    {
        // Chain: C -> B -> A (C depends on B, B depends on A)
        // Registered in reverse: C, B, A
        $baseCollector = $this->createBaseCollector(
            (new MetricBag())->with('input:test', 5),
        );

        $collectorA = $this->createDerivedCollector(
            name: 'step-a',
            requires: [],
            provides: ['step_a_result'],
            calculate: static fn(MetricBag $bag): MetricBag =>
                (new MetricBag())->with('step_a_result', ($bag->get('input') ?? 0) * 10),
        );

        $collectorB = $this->createDerivedCollector(
            name: 'step-b',
            requires: ['step-a'],
            provides: ['step_b_result'],
            calculate: static fn(MetricBag $bag): MetricBag =>
                (new MetricBag())->with('step_b_result', ($bag->get('step_a_result') ?? 0) + 100),
        );

        $collectorC = $this->createDerivedCollector(
            name: 'step-c',
            requires: ['step-b'],
            provides: ['step_c_result'],
            calculate: static fn(MetricBag $bag): MetricBag =>
                (new MetricBag())->with('step_c_result', ($bag->get('step_b_result') ?? 0) * 2),
        );

        // Reverse order: C, B, A
        $composite = new CompositeCollector([$baseCollector], [$collectorC, $collectorB, $collectorA]);
        $result = $composite->collect(new SplFileInfo(__FILE__), []);

        // Expected chain: input=5 -> A: 5*10=50 -> B: 50+100=150 -> C: 150*2=300
        self::assertSame(50, $result->metrics->get('step_a_result:test'));

        // BUG: Without topological sort, B and C get wrong inputs
        self::assertSame(
            150,
            $result->metrics->get('step_b_result:test'),
            'BUG: step-b runs before step-a, gets step_a_result=null, computes 0+100=100 instead of 150',
        );
        self::assertSame(
            300,
            $result->metrics->get('step_c_result:test'),
            'BUG: step-c runs before step-b, gets step_b_result=null, computes 0*2=0 instead of 300',
        );
    }

    #[Test]
    public function applyDerivedCollectorsDoesNotCallRequires(): void
    {
        // This test verifies that requires() is never called during applyDerivedCollectors.
        // The derived collectors iterate in insertion order with no dependency analysis.
        $baseCollector = $this->createBaseCollector(
            (new MetricBag())->with('value:fqn', 1),
        );

        $requiresCallCount = 0;

        $derived = self::createStub(DerivedCollectorInterface::class);
        $derived->method('getName')->willReturn('test-derived');
        $derived->method('provides')->willReturn(['derived_value']);
        $derived->method('getMetricDefinitions')->willReturn([]);
        $derived->method('calculate')->willReturn(
            (new MetricBag())->with('derived_value', 42),
        );
        $derived->method('requires')->willReturnCallback(function () use (&$requiresCallCount): array {
            $requiresCallCount++;

            return [];
        });

        $composite = new CompositeCollector([$baseCollector], [$derived]);
        $composite->collect(new SplFileInfo(__FILE__), []);

        // BUG: CompositeCollector never calls requires() on derived collectors.
        // It should use requires() to determine execution order, like GlobalCollectorSorter does.
        self::assertSame(
            0,
            $requiresCallCount,
            'BUG CONFIRMED: CompositeCollector::applyDerivedCollectors() '
            . 'never calls requires() on derived collectors — no dependency resolution',
        );
    }

    /**
     * @param list<string> $requires
     * @param list<string> $provides
     * @param callable(MetricBag): MetricBag $calculate
     */
    private function createDerivedCollector(
        string $name,
        array $requires,
        array $provides,
        callable $calculate,
    ): DerivedCollectorInterface {
        $mock = self::createStub(DerivedCollectorInterface::class);
        $mock->method('getName')->willReturn($name);
        $mock->method('requires')->willReturn($requires);
        $mock->method('provides')->willReturn($provides);
        $mock->method('getMetricDefinitions')->willReturn([]);
        $mock->method('calculate')->willReturnCallback($calculate);

        return $mock;
    }

    private function createBaseCollector(MetricBag $metrics): MetricCollectorInterface
    {
        $collector = self::createStub(MetricCollectorInterface::class);
        $collector->method('getName')->willReturn('base');
        $collector->method('getVisitor')->willReturn(new class extends NodeVisitorAbstract {});
        $collector->method('collect')->willReturn($metrics);

        return $collector;
    }
}
