<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Coupling;

use AiMessDetector\Analysis\Collection\Dependency\DependencyGraph;
use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Metrics\Coupling\DistanceCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DistanceCollector::class)]
final class DistanceCollectorTest extends TestCase
{
    private DistanceCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new DistanceCollector();
    }

    #[Test]
    public function getName_returnsDistance(): void
    {
        self::assertSame('distance', $this->collector->getName());
    }

    #[Test]
    public function requires_returnsRequiredMetrics(): void
    {
        self::assertSame(['instability', 'abstractness'], $this->collector->requires());
    }

    #[Test]
    public function provides_returnsDistance(): void
    {
        self::assertSame(['distance'], $this->collector->provides());
    }

    #[Test]
    public function getMetricDefinitions_returnsOneDefinition(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(1, $definitions);

        $distance = $definitions[0];
        self::assertSame('distance', $distance->name);
        self::assertSame(SymbolLevel::Namespace_, $distance->collectedAt);
        self::assertNotEmpty($distance->aggregations);
        self::assertSame([AggregationStrategy::Average], $distance->getStrategiesForLevel(SymbolLevel::Project));
        self::assertTrue($distance->hasAggregationsForLevel(SymbolLevel::Project));
    }

    #[Test]
    public function calculate_onMainSequence(): void
    {
        // A + I = 1 → distance = 0 (ideal)
        // Abstractness = 0.5, Instability = 0.5
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\Balanced');

        $metrics = (new MetricBag())
            ->with('abstractness', 0.5)
            ->with('instability', 0.5);

        $repository->add($nsPath, $metrics, '', 0);

        $graph = $this->createEmptyGraph();

        $this->collector->calculate($graph, $repository);

        $result = $repository->get($nsPath);
        self::assertEqualsWithDelta(0.0, $result->get('distance'), 0.001);
    }

    #[Test]
    public function calculate_concreteStable_zoneOfPain(): void
    {
        // A = 0, I = 0 → distance = 1 (zone of pain - hard to change)
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\ZoneOfPain');

        $metrics = (new MetricBag())
            ->with('abstractness', 0.0)
            ->with('instability', 0.0);

        $repository->add($nsPath, $metrics, '', 0);

        $graph = $this->createEmptyGraph();

        $this->collector->calculate($graph, $repository);

        $result = $repository->get($nsPath);
        self::assertEqualsWithDelta(1.0, $result->get('distance'), 0.001);
    }

    #[Test]
    public function calculate_abstractUnstable_zoneOfUselessness(): void
    {
        // A = 1, I = 1 → distance = 1 (zone of uselessness - too abstract)
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\ZoneOfUselessness');

        $metrics = (new MetricBag())
            ->with('abstractness', 1.0)
            ->with('instability', 1.0);

        $repository->add($nsPath, $metrics, '', 0);

        $graph = $this->createEmptyGraph();

        $this->collector->calculate($graph, $repository);

        $result = $repository->get($nsPath);
        self::assertEqualsWithDelta(1.0, $result->get('distance'), 0.001);
    }

    #[Test]
    public function calculate_typicalCase(): void
    {
        // A = 0.3, I = 0.4 → distance = |0.3 + 0.4 - 1| = 0.3
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\Service');

        $metrics = (new MetricBag())
            ->with('abstractness', 0.3)
            ->with('instability', 0.4);

        $repository->add($nsPath, $metrics, '', 0);

        $graph = $this->createEmptyGraph();

        $this->collector->calculate($graph, $repository);

        $result = $repository->get($nsPath);
        self::assertEqualsWithDelta(0.3, $result->get('distance'), 0.001);
    }

    #[Test]
    public function calculate_missingMetrics_usesDefaults(): void
    {
        // Missing both abstractness and instability → defaults to 0
        // distance = |0 + 0 - 1| = 1
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\NoMetrics');

        $repository->add($nsPath, new MetricBag(), '', 0);

        $graph = $this->createEmptyGraph();

        $this->collector->calculate($graph, $repository);

        $result = $repository->get($nsPath);
        self::assertEqualsWithDelta(1.0, $result->get('distance'), 0.001);
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
            [],
            [],
        );
    }
}
