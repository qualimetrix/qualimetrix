<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit\Distance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Coupling\Distance\DistanceCollector;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
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
            ['coupling.instability', 'coupling.abstractness', 'coupling.instability-own', 'coupling.abstractness-own', 'coupling.ca', 'coupling.ce', 'coupling.ca-own', 'coupling.ce-own', 'size.class-count', 'size.trait-count', 'size.interface-count', 'size.implementing-enum-count'],
            $this->collector->requires(),
        );
    }

    #[Test]
    public function itProvidesTheDistanceMetricInBothScopes(): void
    {
        self::assertSame(['coupling.distance', 'coupling.distance-own', 'coupling.distance-own.avg', 'coupling.distance-own.count'], $this->collector->provides());
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

        self::assertCount(4, $definitions);

        $published = $definitions[0];
        self::assertSame('coupling.distance', $published->name);
        self::assertSame(SymbolLevel::Namespace_, $published->collectedAt);
        self::assertFalse($published->hasAggregationsForLevel(SymbolLevel::Project));

        $own = $definitions[1];
        self::assertSame('coupling.distance-own', $own->name);
        self::assertSame(SymbolLevel::Namespace_, $own->collectedAt);
        self::assertFalse($own->hasAggregationsForLevel(SymbolLevel::Project));
        self::assertSame('coupling.distance-own.avg', $definitions[2]->name);
        self::assertSame(SymbolLevel::Project, $definitions[2]->collectedAt);
        self::assertSame('coupling.distance-own.count', $definitions[3]->name);
        self::assertSame(SymbolLevel::Project, $definitions[3]->collectedAt);
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

        $this->calculateWithCoupling($this->createEmptyGraph(), $repository);
        $result = $repository->get($nsPath);

        self::assertEqualsWithDelta(0.0, $result->get('coupling.distance'), 0.0001);
        self::assertEqualsWithDelta(0.75, $result->get('coupling.distance-own'), 0.0001);
    }

    /**
     * A namespace without a graph edge has conventional I = 0 but no
     * measured distance in either scope.
     */
    #[Test]
    public function itOmitsBothDistancesForAnOwnScopeWithoutEdges(): void
    {
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\Leaf');

        $repository->add($nsPath, (new MetricBag())
            ->with('coupling.abstractness', 0.25)
            ->with('coupling.instability', 0.0)
            ->with('coupling.abstractness-own', 0.25)
            ->with('coupling.instability-own', 0.0)
            ->with('coupling.ca', 0)
            ->with('coupling.ce', 0)
            ->with('coupling.ca-own', 0)
            ->with('coupling.ce-own', 0), null, 0);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertNull($repository->get($nsPath)->get('coupling.distance'));
        self::assertNull($repository->get($nsPath)->get('coupling.distance-own'));
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

        $this->calculateWithCoupling($this->createEmptyGraph(), $repository);
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

        $this->calculateWithCoupling($graph, $repository);

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

        $this->calculateWithCoupling($graph, $repository);

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

        $this->calculateWithCoupling($graph, $repository);

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

        $this->calculateWithCoupling($graph, $repository);

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

        $this->calculateWithCoupling($this->createEmptyGraph(), $repository);

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

        $this->calculateWithCoupling($this->createEmptyGraph(), $repository);

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

        $this->calculateWithCoupling($this->createEmptyGraph(), $repository);

        self::assertEqualsWithDelta(0.25, $repository->get($nsPath)->get('coupling.distance'), 0.0001);
        self::assertNull($repository->get($nsPath)->get('coupling.distance-own'));
    }

    #[Test]
    public function itPublishesATypeWeightedProjectDistanceFromMeasuredOwnScopes(): void
    {
        $repository = new InMemoryMetricRepository();
        $parent = SymbolPath::forNamespace('App');
        $child = SymbolPath::forNamespace('App\Child');
        $repository->add(SymbolPath::forProject(), new MetricBag(), null, null);
        $repository->add($parent, MetricBag::fromArray([
            'size.class-count' => 1,
            'size.trait-count' => 0,
            'size.interface-count' => 0,
            'size.implementing-enum-count' => 0,
            'coupling.abstractness' => 0.0,
            'coupling.instability' => 0.0,
            'coupling.ca' => 1,
            'coupling.ce' => 0,
            'coupling.abstractness-own' => 0.0,
            'coupling.instability-own' => 0.0,
            'coupling.ca-own' => 1,
            'coupling.ce-own' => 0,
        ]), null, 0);
        $repository->add($child, MetricBag::fromArray([
            'size.class-count' => 1,
            'size.trait-count' => 1,
            'size.interface-count' => 1,
            'size.implementing-enum-count' => 1,
            'size.enum-count' => 10,
            'coupling.abstractness' => 0.25,
            'coupling.instability' => 1.0,
            'coupling.ca' => 0,
            'coupling.ce' => 1,
            'coupling.abstractness-own' => 0.25,
            'coupling.instability-own' => 1.0,
            'coupling.ca-own' => 0,
            'coupling.ce-own' => 1,
        ]), null, 0);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertEqualsWithDelta(1.0, $repository->get($parent)->get('coupling.distance-own'), 0.0001);
        self::assertEqualsWithDelta(0.25, $repository->get($child)->get('coupling.distance-own'), 0.0001);
        self::assertEqualsWithDelta(0.4, $repository->get(SymbolPath::forProject())->get('coupling.distance-own.avg'), 0.0001);
        self::assertSame(2, $repository->get(SymbolPath::forProject())->get('coupling.distance-own.count'));
    }

    #[Test]
    public function itLeavesProjectDistanceUnmeasuredWhenNoOwnScopeHasEdges(): void
    {
        $repository = new InMemoryMetricRepository();
        $namespace = SymbolPath::forNamespace('App');
        $repository->add(SymbolPath::forProject(), new MetricBag(), null, null);
        $repository->add($namespace, MetricBag::fromArray([
            'size.class-count' => 1,
            'size.trait-count' => 0,
            'size.interface-count' => 0,
            'size.implementing-enum-count' => 0,
            'coupling.abstractness' => 0.0,
            'coupling.instability' => 0.0,
            'coupling.ca' => 0,
            'coupling.ce' => 0,
            'coupling.abstractness-own' => 0.0,
            'coupling.instability-own' => 0.0,
            'coupling.ca-own' => 0,
            'coupling.ce-own' => 0,
        ]), null, 0);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertNull($repository->get($namespace)->get('coupling.distance'));
        self::assertNull($repository->get($namespace)->get('coupling.distance-own'));
        self::assertNull($repository->get(SymbolPath::forProject())->get('coupling.distance-own.avg'));
        self::assertNull($repository->get(SymbolPath::forProject())->get('coupling.distance-own.count'));
    }

    #[Test]
    public function itDoesNotTreatMissingCouplingAsZero(): void
    {
        $repository = new InMemoryMetricRepository();
        $namespace = SymbolPath::forNamespace('App');
        $repository->add(SymbolPath::forProject(), new MetricBag(), null, null);
        $repository->add($namespace, MetricBag::fromArray([
            'size.class-count' => 1,
            'size.trait-count' => 0,
            'size.interface-count' => 0,
            'size.implementing-enum-count' => 0,
            'coupling.abstractness' => 0.0,
            'coupling.instability' => 0.0,
            'coupling.abstractness-own' => 0.0,
            'coupling.instability-own' => 0.0,
            'coupling.ca' => 1,
            'coupling.ca-own' => 1,
        ]), null, 0);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertNull($repository->get($namespace)->get('coupling.distance'));
        self::assertNull($repository->get($namespace)->get('coupling.distance-own'));
        self::assertNull($repository->get(SymbolPath::forProject())->get('coupling.distance-own.avg'));
    }

    #[Test]
    public function itKeepsMeasuredOwnDistanceOutOfTheProjectWithoutPositiveCompleteTypeCounts(): void
    {
        foreach ([
            'missing' => ['size.class-count' => 1],
            'zero' => ['size.class-count' => 0, 'size.trait-count' => 0, 'size.interface-count' => 0, 'size.implementing-enum-count' => 0],
        ] as $case => $counts) {
            $repository = new InMemoryMetricRepository();
            $namespace = SymbolPath::forNamespace('App');
            $repository->add(SymbolPath::forProject(), new MetricBag(), null, null);
            $repository->add($namespace, MetricBag::fromArray([
                ...$counts,
                'coupling.abstractness' => 0.0, 'coupling.instability' => 0.0,
                'coupling.ca' => 1, 'coupling.ce' => 0,
                'coupling.abstractness-own' => 0.0, 'coupling.instability-own' => 0.25,
                'coupling.ca-own' => 0, 'coupling.ce-own' => 1,
            ]), null, 0);

            $this->collector->calculate($this->createEmptyGraph(), $repository);

            self::assertSame(1.0, $repository->get($namespace)->get('coupling.distance'), $case);
            self::assertSame(0.75, $repository->get($namespace)->get('coupling.distance-own'), $case);
            self::assertNull($repository->get(SymbolPath::forProject())->get('coupling.distance-own.avg'), $case);
            self::assertNull($repository->get(SymbolPath::forProject())->get('coupling.distance-own.count'), $case);
        }
    }

    private function calculateWithCoupling(DependencyGraphInterface $graph, InMemoryMetricRepository $repository): void
    {
        foreach ($repository->all(SymbolLevel::Namespace_) as $info) {
            $path = $info->symbolPath;
            foreach (['coupling.ca', 'coupling.ce', 'coupling.ca-own', 'coupling.ce-own'] as $key) {
                if ($repository->get($path)->get($key) === null) {
                    $repository->addScalar($path, $key, 1);
                }
            }
        }
        $this->collector->calculate($graph, $repository);
    }

    private function createEmptyGraph(): DependencyGraphInterface
    {
        return AdjacencyGraphBuilder::empty();
    }
}
