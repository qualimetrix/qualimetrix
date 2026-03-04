<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Coupling;

use AiMessDetector\Analysis\Collection\Dependency\DependencyGraph;
use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Violation\SymbolPath;
use AiMessDetector\Metrics\Coupling\AbstractnessCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractnessCollector::class)]
final class AbstractnessCollectorTest extends TestCase
{
    private AbstractnessCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new AbstractnessCollector();
    }

    #[Test]
    public function getName_returnsAbstractness(): void
    {
        self::assertSame('abstractness', $this->collector->getName());
    }

    #[Test]
    public function requires_returnsRequiredMetrics(): void
    {
        self::assertSame(
            ['classCount.sum', 'enumCount.sum', 'traitCount.sum', 'abstractClassCount.sum', 'interfaceCount.sum'],
            $this->collector->requires(),
        );
    }

    #[Test]
    public function provides_returnsAbstractness(): void
    {
        self::assertSame(['abstractness'], $this->collector->provides());
    }

    #[Test]
    public function calculate_computesAbstractness(): void
    {
        // Namespace with 10 classes + 2 enums + 3 traits = 15 types
        // 2 abstract + 3 interfaces = 5 abstractions
        // Abstractness = 5 / 15 = 0.333
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\Domain');

        $metrics = (new MetricBag())
            ->with('classCount.sum', 10)
            ->with('enumCount.sum', 2)
            ->with('traitCount.sum', 3)
            ->with('abstractClassCount.sum', 2)
            ->with('interfaceCount.sum', 3);

        $repository->add($nsPath, $metrics, '', 0);

        $graph = $this->createEmptyGraph();

        $this->collector->calculate($graph, $repository);

        $result = $repository->get($nsPath);
        self::assertEqualsWithDelta(0.333, $result->get('abstractness'), 0.001);
    }

    #[Test]
    public function calculate_fullyConcreteNamespace(): void
    {
        // All concrete types: 5 classes + 2 enums + 1 trait, no abstractions
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\Concrete');

        $metrics = (new MetricBag())
            ->with('classCount.sum', 5)
            ->with('enumCount.sum', 2)
            ->with('traitCount.sum', 1)
            ->with('abstractClassCount.sum', 0)
            ->with('interfaceCount.sum', 0);

        $repository->add($nsPath, $metrics, '', 0);

        $graph = $this->createEmptyGraph();

        $this->collector->calculate($graph, $repository);

        $result = $repository->get($nsPath);
        self::assertEqualsWithDelta(0.0, $result->get('abstractness'), 0.001);
    }

    #[Test]
    public function calculate_fullyAbstractNamespace(): void
    {
        // All abstractions: 5 types (2 abstract classes + 3 interfaces), 5 abstractions
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\Contracts');

        $metrics = (new MetricBag())
            ->with('classCount.sum', 5)
            ->with('enumCount.sum', 0)
            ->with('traitCount.sum', 0)
            ->with('abstractClassCount.sum', 2)
            ->with('interfaceCount.sum', 3);

        $repository->add($nsPath, $metrics, '', 0);

        $graph = $this->createEmptyGraph();

        $this->collector->calculate($graph, $repository);

        $result = $repository->get($nsPath);
        self::assertEqualsWithDelta(1.0, $result->get('abstractness'), 0.001);
    }

    #[Test]
    public function calculate_emptyNamespace_returnsZero(): void
    {
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\Empty');

        $metrics = (new MetricBag())
            ->with('classCount.sum', 0)
            ->with('enumCount.sum', 0)
            ->with('traitCount.sum', 0)
            ->with('abstractClassCount.sum', 0)
            ->with('interfaceCount.sum', 0);

        $repository->add($nsPath, $metrics, '', 0);

        $graph = $this->createEmptyGraph();

        $this->collector->calculate($graph, $repository);

        $result = $repository->get($nsPath);
        self::assertEqualsWithDelta(0.0, $result->get('abstractness'), 0.001);
    }

    #[Test]
    public function calculate_withEnumsAndTraits_preventAbstractnessOverOne(): void
    {
        // Edge case: namespace with 2 interfaces + 6 enums = 8 total items, but only 6 are types!
        // totalTypes = 0 (classes) + 6 (enums) + 0 (traits) = 6
        // totalAbstractions = 0 (abstract) + 2 (interfaces) = 2
        // Old formula: A = 2/0 = undefined (division by zero or infinity)
        // New formula: A = 2/6 = 0.333
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\Mixed');

        $metrics = (new MetricBag())
            ->with('classCount.sum', 0)
            ->with('enumCount.sum', 6)
            ->with('traitCount.sum', 0)
            ->with('abstractClassCount.sum', 0)
            ->with('interfaceCount.sum', 2);

        $repository->add($nsPath, $metrics, '', 0);

        $graph = $this->createEmptyGraph();

        $this->collector->calculate($graph, $repository);

        $result = $repository->get($nsPath);
        $abstractness = $result->get('abstractness');

        // Must be in [0, 1] range
        self::assertGreaterThanOrEqual(0.0, $abstractness);
        self::assertLessThanOrEqual(1.0, $abstractness);
        self::assertEqualsWithDelta(0.333, $abstractness, 0.001);
    }

    private function createEmptyGraph(): DependencyGraph
    {
        return new DependencyGraph(
            [],
            [],
            [],
            [],
            [],
            [],
            [],
        );
    }
}
