<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Collection\Metric;

use LogicException;
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
 * Regression coverage for the derived-collector execution contract.
 *
 * CompositeCollector sorts derived collectors by their named `requires()`
 * dependencies and merges every output into the next collector's input.
 */
#[CoversClass(CompositeCollector::class)]
#[Group('regression')]
final class DerivedCollectorSortTest extends TestCase
{
    #[Test]
    public function itAccumulatesOutputsForDependentDerivedCollectors(): void
    {
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

        // B runs before A, and A receives B's accumulated output.
        $composite = new CompositeCollector([$baseCollector], [$collectorB, $collectorA]);
        $result = $composite->collect(new SplFileInfo(__FILE__), []);

        self::assertSame(10, $result->metrics->get('raw:App\Service::method'));
        self::assertSame(20, $result->metrics->get('intermediate:App\Service::method'));

        self::assertSame(21, $result->metrics->get('final:App\Service::method'));
    }

    #[Test]
    public function itSortsDependentDerivedCollectorsBeforeCalculation(): void
    {
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

        // Registration order is intentionally reverse dependency order.
        $composite = new CompositeCollector([$baseCollector], [$collectorA, $collectorB]);
        $result = $composite->collect(new SplFileInfo(__FILE__), []);

        self::assertSame(10, $result->metrics->get('raw:App\Service::method'));
        self::assertSame(20, $result->metrics->get('intermediate:App\Service::method'));
        self::assertSame(21, $result->metrics->get('final:App\Service::method'));
    }

    #[Test]
    public function itSortsAThreeCollectorChainRegisteredInReverseOrder(): void
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

        // Reverse order: C, B, A. The dependency graph determines execution.
        $composite = new CompositeCollector([$baseCollector], [$collectorC, $collectorB, $collectorA]);
        $result = $composite->collect(new SplFileInfo(__FILE__), []);

        self::assertSame(50, $result->metrics->get('step_a_result:test'));
        self::assertSame(150, $result->metrics->get('step_b_result:test'));
        self::assertSame(300, $result->metrics->get('step_c_result:test'));
    }

    #[Test]
    public function itRejectsCyclicDerivedCollectorDependencies(): void
    {
        $baseCollector = $this->createBaseCollector(
            (new MetricBag())->with('value:fqn', 1),
        );

        $collectorA = $this->createDerivedCollector(
            name: 'collector-a',
            requires: ['collector-b'],
            provides: ['a'],
            calculate: static fn(MetricBag $bag): MetricBag => new MetricBag(),
        );

        $collectorB = $this->createDerivedCollector(
            name: 'collector-b',
            requires: ['collector-a'],
            provides: ['b'],
            calculate: static fn(MetricBag $bag): MetricBag => new MetricBag(),
        );

        $composite = new CompositeCollector([$baseCollector], [$collectorA, $collectorB]);

        self::expectException(LogicException::class);
        self::expectExceptionMessageMatches('/Cyclic dependency.*collector-a.*collector-b|Cyclic dependency.*collector-b.*collector-a/');

        $composite->collect(new SplFileInfo(__FILE__), []);
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
