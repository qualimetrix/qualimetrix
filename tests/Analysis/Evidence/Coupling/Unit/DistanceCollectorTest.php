<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Coupling\DistanceCollector;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;

#[CoversClass(DistanceCollector::class)]
final class DistanceCollectorTest extends TestCase
{
    private DistanceCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new DistanceCollector();
    }

    #[Test]
    public function itNamesItselfDistance(): void
    {
        self::assertSame('distance', $this->collector->getName());
    }

    #[Test]
    public function itRequiresInstabilityAndAbstractnessInBothScopes(): void
    {
        self::assertSame(
            ['coupling.instability', 'coupling.abstractness', 'coupling.instability-own', 'coupling.abstractness-own'],
            $this->collector->requires(),
        );
    }

    #[Test]
    public function itProvidesTheDistanceMetricInBothScopes(): void
    {
        self::assertSame(['coupling.distance', 'coupling.distance-own'], $this->collector->provides());
    }

    /**
     * Which of the two scopes the project average is taken over is the whole
     * point of the pair. The published value stays the subtree rollup a reader
     * of one namespace expects, and carries no project aggregation: a parent
     * and its children in one average would weigh the same declarations twice.
     */
    #[Test]
    public function itAveragesTheOwnScopeIntoTheProjectAndLeavesTheSubtreeRollupUnaggregated(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(2, $definitions);

        $published = $definitions[0];
        self::assertSame('coupling.distance', $published->name);
        self::assertSame(SymbolLevel::Namespace_, $published->collectedAt);
        self::assertFalse($published->hasAggregationsForLevel(SymbolLevel::Project));

        $own = $definitions[1];
        self::assertSame('coupling.distance-own', $own->name);
        self::assertSame(SymbolLevel::Namespace_, $own->collectedAt);
        self::assertSame([AggregationStrategy::Average], $own->getStrategiesForLevel(SymbolLevel::Project));
        self::assertTrue($own->hasAggregationsForLevel(SymbolLevel::Project));
    }

    #[Test]
    public function itComputesTheOwnDistanceFromTheOwnScopeInputs(): void
    {
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App');

        $repository->add($nsPath, (new MetricBag())
            ->with('coupling.abstractness', 0.5)
            ->with('coupling.instability', 0.5)
            ->with('coupling.abstractness-own', 0.0)
            ->with('coupling.instability-own', 0.25), null, 0);

        $this->collector->calculate($this->createEmptyGraph(), $repository);
        $result = $repository->get($nsPath);

        self::assertEqualsWithDelta(0.0, $result->get('coupling.distance'), 0.0001);
        self::assertEqualsWithDelta(0.75, $result->get('coupling.distance-own'), 0.0001);
    }

    /**
     * A namespace that declares types but has no edge at all is at I = 0 by
     * the published convention: Ce = Ca = 0 gives instability 0, so the own
     * distance is |A + 0 - 1|. Whether such a namespace is judged is the
     * rule's call, not this collector's.
     */
    #[Test]
    public function itTreatsAnOwnScopeWithoutEdgesAsFullyStable(): void
    {
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\Leaf');

        $repository->add($nsPath, (new MetricBag())
            ->with('coupling.abstractness', 0.25)
            ->with('coupling.instability', 0.0)
            ->with('coupling.abstractness-own', 0.25)
            ->with('coupling.instability-own', 0.0), null, 0);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertEqualsWithDelta(0.75, $repository->get($nsPath)->get('coupling.distance-own'), 0.0001);
    }

    /**
     * No own abstractness means the namespace declares no type of its own, and
     * the project average must not receive a value for it.
     */
    #[Test]
    public function itPublishesNoOwnDistanceWithoutAnOwnAbstractness(): void
    {
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App');

        $repository->add($nsPath, (new MetricBag())
            ->with('coupling.abstractness', 0.5)
            ->with('coupling.instability', 0.5)
            ->with('coupling.instability-own', 0.25), null, 0);

        $this->collector->calculate($this->createEmptyGraph(), $repository);
        $result = $repository->get($nsPath);

        self::assertNotNull($result->get('coupling.distance'));
        self::assertNull($result->get('coupling.distance-own'));
    }

    #[Test]
    public function itScoresZeroDistanceOnTheMainSequence(): void
    {
        // A + I = 1 → distance = 0 (ideal)
        // Abstractness = 0.5, Instability = 0.5
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\Balanced');

        $metrics = (new MetricBag())
            ->with('coupling.abstractness', 0.5)
            ->with('coupling.instability', 0.5);

        $repository->add($nsPath, $metrics, null, 0);

        $graph = $this->createEmptyGraph();

        $this->collector->calculate($graph, $repository);

        $result = $repository->get($nsPath);
        self::assertEqualsWithDelta(0.0, $result->get('coupling.distance'), 0.001);
    }

    #[Test]
    public function itScoresMaximumDistanceForAConcreteStableZoneOfPain(): void
    {
        // A = 0, I = 0 → distance = 1 (zone of pain - hard to change)
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\ZoneOfPain');

        $metrics = (new MetricBag())
            ->with('coupling.abstractness', 0.0)
            ->with('coupling.instability', 0.0);

        $repository->add($nsPath, $metrics, null, 0);

        $graph = $this->createEmptyGraph();

        $this->collector->calculate($graph, $repository);

        $result = $repository->get($nsPath);
        self::assertEqualsWithDelta(1.0, $result->get('coupling.distance'), 0.001);
    }

    #[Test]
    public function itScoresMaximumDistanceForAnAbstractUnstableZoneOfUselessness(): void
    {
        // A = 1, I = 1 → distance = 1 (zone of uselessness - too abstract)
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\ZoneOfUselessness');

        $metrics = (new MetricBag())
            ->with('coupling.abstractness', 1.0)
            ->with('coupling.instability', 1.0);

        $repository->add($nsPath, $metrics, null, 0);

        $graph = $this->createEmptyGraph();

        $this->collector->calculate($graph, $repository);

        $result = $repository->get($nsPath);
        self::assertEqualsWithDelta(1.0, $result->get('coupling.distance'), 0.001);
    }

    #[Test]
    public function itScoresTheAbsoluteDeviationFromTheMainSequence(): void
    {
        // A = 0.3, I = 0.4 → distance = |0.3 + 0.4 - 1| = 0.3
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\Service');

        $metrics = (new MetricBag())
            ->with('coupling.abstractness', 0.3)
            ->with('coupling.instability', 0.4);

        $repository->add($nsPath, $metrics, null, 0);

        $graph = $this->createEmptyGraph();

        $this->collector->calculate($graph, $repository);

        $result = $repository->get($nsPath);
        self::assertEqualsWithDelta(0.3, $result->get('coupling.distance'), 0.001);
    }

    /**
     * A namespace that declares only functions is in the repository but not in
     * the class graph, so no instability is ever published for it. Read as 0,
     * the missing input made it |0 + 0 - 1| = 1.0 -- the worst distance there
     * is, for a namespace distance does not describe. No input, no value.
     */
    #[Test]
    public function itPublishesNoDistanceForANamespaceWithoutInstability(): void
    {
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\Functions');

        $repository->add($nsPath, (new MetricBag())->with('coupling.abstractness', 0.0), null, 0);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        $result = $repository->get($nsPath);
        self::assertNull($result->get('coupling.distance'));
        self::assertNull($result->get('coupling.distance-own'));
    }

    #[Test]
    public function itPublishesNoDistanceWithoutAbstractness(): void
    {
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\NoMetrics');

        $repository->add($nsPath, (new MetricBag())->with('coupling.instability', 0.0), null, 0);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertNull($repository->get($nsPath)->get('coupling.distance'));
    }

    #[Test]
    public function itPublishesNoOwnDistanceWithoutAnOwnInstability(): void
    {
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\Leaf');

        $repository->add($nsPath, (new MetricBag())
            ->with('coupling.abstractness', 0.25)
            ->with('coupling.instability', 0.5)
            ->with('coupling.abstractness-own', 0.25), null, 0);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertEqualsWithDelta(0.25, $repository->get($nsPath)->get('coupling.distance'), 0.0001);
        self::assertNull($repository->get($nsPath)->get('coupling.distance-own'));
    }

    private function createEmptyGraph(): DependencyGraphInterface
    {
        return AdjacencyGraphBuilder::empty();
    }
}
