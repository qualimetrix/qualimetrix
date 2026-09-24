<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Evidence\Coupling\CouplingAnalysis;
use Qualimetrix\Analysis\Evidence\Coupling\CouplingCollector;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\DependencyModel\DependencyGraphBuilder;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;

#[CoversClass(CouplingCollector::class)]
final class CouplingCollectorTest extends TestCase
{
    private CouplingCollector $collector;
    private DependencyGraphBuilderInterface $graphBuilder;

    protected function setUp(): void
    {
        $this->collector = new CouplingCollector(new CouplingAnalysis());
        $this->graphBuilder = AdjacencyGraphBuilder::builder();
    }

    #[Test]
    public function itIsNamedCoupling(): void
    {
        self::assertSame('coupling', $this->collector->getName());
    }

    #[Test]
    public function itRequiresNoUpstreamMetrics(): void
    {
        self::assertSame([], $this->collector->requires());
    }

    #[Test]
    public function itProvidesTheCouplingMetricNames(): void
    {
        self::assertSame(['coupling.ca', 'coupling.ce', 'coupling.cbo', 'coupling.instability', 'coupling.ce-packages', 'coupling.cbo-app', 'coupling.ce-framework', 'coupling.ca-own', 'coupling.ce-own', 'coupling.instability-own'], $this->collector->provides());
    }

    #[Test]
    public function itDeclaresTenMetricDefinitionsWithTheirAggregationStrategies(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(10, $definitions);

        // ca metric
        $ca = $definitions[0];
        self::assertSame('coupling.ca', $ca->name);
        self::assertSame(SymbolLevel::Class_, $ca->collectedAt);
        self::assertSame(
            [AggregationStrategy::Sum],
            $ca->getStrategiesForLevel(SymbolLevel::Namespace_),
        );
        self::assertSame([], $ca->getStrategiesForLevel(SymbolLevel::Project));

        // ce metric
        $ce = $definitions[1];
        self::assertSame('coupling.ce', $ce->name);
        self::assertSame(SymbolLevel::Class_, $ce->collectedAt);
        self::assertSame(
            [
                AggregationStrategy::Sum,
                AggregationStrategy::Average,
                AggregationStrategy::Max,
                AggregationStrategy::Percentile95,
            ],
            $ce->getStrategiesForLevel(SymbolLevel::Namespace_),
        );
        self::assertSame(
            [
                AggregationStrategy::Sum,
                AggregationStrategy::Average,
                AggregationStrategy::Max,
                AggregationStrategy::Percentile95,
            ],
            $ce->getStrategiesForLevel(SymbolLevel::Project),
        );

        // cbo metric
        $cbo = $definitions[2];
        self::assertSame('coupling.cbo', $cbo->name);
        self::assertSame(SymbolLevel::Class_, $cbo->collectedAt);
        self::assertSame(
            [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max, AggregationStrategy::Percentile95],
            $cbo->getStrategiesForLevel(SymbolLevel::Namespace_),
        );
        self::assertSame(
            [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max, AggregationStrategy::Percentile95],
            $cbo->getStrategiesForLevel(SymbolLevel::Project),
        );

        // instability metric
        $instability = $definitions[3];
        self::assertSame('coupling.instability', $instability->name);
        self::assertSame(SymbolLevel::Class_, $instability->collectedAt);
        self::assertSame(
            [AggregationStrategy::Average],
            $instability->getStrategiesForLevel(SymbolLevel::Namespace_),
        );
        self::assertSame([], $instability->getStrategiesForLevel(SymbolLevel::Project));

        // ce_packages metric
        $cePackages = $definitions[4];
        self::assertSame('coupling.ce-packages', $cePackages->name);
        self::assertSame(SymbolLevel::Class_, $cePackages->collectedAt);
        self::assertSame(
            [AggregationStrategy::Average, AggregationStrategy::Max, AggregationStrategy::Percentile95],
            $cePackages->getStrategiesForLevel(SymbolLevel::Namespace_),
        );
        self::assertSame(
            [AggregationStrategy::Average, AggregationStrategy::Max, AggregationStrategy::Percentile95],
            $cePackages->getStrategiesForLevel(SymbolLevel::Project),
        );

        // cbo_app metric
        $cboApp = $definitions[5];
        self::assertSame('coupling.cbo-app', $cboApp->name);
        self::assertSame(SymbolLevel::Class_, $cboApp->collectedAt);
        self::assertSame(
            [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max, AggregationStrategy::Percentile95],
            $cboApp->getStrategiesForLevel(SymbolLevel::Namespace_),
        );
        self::assertSame(
            [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max, AggregationStrategy::Percentile95],
            $cboApp->getStrategiesForLevel(SymbolLevel::Project),
        );

        // the own-scope namespace metrics, which no level aggregates: the pair
        // exists so a parent namespace can answer for its own declarations as
        // well as for its subtree, and only distance folds up from there.
        foreach ([7 => 'coupling.ca-own', 8 => 'coupling.ce-own', 9 => 'coupling.instability-own'] as $index => $name) {
            $own = $definitions[$index];
            self::assertSame($name, $own->name);
            self::assertSame(SymbolLevel::Namespace_, $own->collectedAt);
            self::assertSame([], $own->getStrategiesForLevel(SymbolLevel::Namespace_));
            self::assertSame([], $own->getStrategiesForLevel(SymbolLevel::Project));
        }

        // ce_framework metric
        $ceFramework = $definitions[6];
        self::assertSame('coupling.ce-framework', $ceFramework->name);
        self::assertSame(SymbolLevel::Class_, $ceFramework->collectedAt);
        self::assertSame(
            [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max],
            $ceFramework->getStrategiesForLevel(SymbolLevel::Namespace_),
        );
        self::assertSame(
            [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max],
            $ceFramework->getStrategiesForLevel(SymbolLevel::Project),
        );
    }

    #[Test]
    public function itComputesEfferentCouplingAndInstabilityForAClass(): void
    {
        // App\Foo depends on Vendor\Bar and Vendor\Baz (Ce = 2)
        // Nothing depends on App\Foo (Ca = 0)
        $deps = [
            $this->dep('App\\Foo', 'Vendor\\Bar'),
            $this->dep('App\\Foo', 'Vendor\\Baz'),
        ];

        $graph = $this->graph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Foo');

        $this->collector->calculate($graph, $repository);

        $fooPath = SymbolPath::forClass('App', 'Foo');
        $fooMetrics = $repository->get($fooPath);

        self::assertSame(0, $fooMetrics->get('coupling.ca'));
        self::assertSame(2, $fooMetrics->get('coupling.ce'));
        self::assertEqualsWithDelta(1.0, $fooMetrics->get('coupling.instability'), 0.001);
    }

    #[Test]
    public function itComputesAfferentCouplingForAClassWithMultipleDependents(): void
    {
        // Both App\Foo and App\Baz depend on App\Bar
        // App\Bar has Ca = 2
        $deps = [
            $this->dep('App\\Foo', 'App\\Bar'),
            $this->dep('App\\Baz', 'App\\Bar'),
        ];

        $graph = $this->graph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Foo');
        $this->registerClass($repository, 'App\\Baz');
        $this->registerClass($repository, 'App\\Bar');

        $this->collector->calculate($graph, $repository);

        $barPath = SymbolPath::forClass('App', 'Bar');
        $barMetrics = $repository->get($barPath);

        self::assertSame(2, $barMetrics->get('coupling.ca'));
        self::assertSame(0, $barMetrics->get('coupling.ce'));
        self::assertEqualsWithDelta(0.0, $barMetrics->get('coupling.instability'), 0.001);
    }

    #[Test]
    public function itComputesInstabilityFromAfferentAndEfferentCoupling(): void
    {
        // App\Service has Ca = 1 (App\Controller depends on it)
        // App\Service has Ce = 2 (depends on Vendor\A and Vendor\B)
        // Instability = 2 / (1 + 2) = 0.666...
        $deps = [
            $this->dep('App\\Controller', 'App\\Service'),
            $this->dep('App\\Service', 'Vendor\\A'),
            $this->dep('App\\Service', 'Vendor\\B'),
        ];

        $graph = $this->graph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Controller');
        $this->registerClass($repository, 'App\\Service');

        $this->collector->calculate($graph, $repository);

        $servicePath = SymbolPath::forClass('App', 'Service');
        $serviceMetrics = $repository->get($servicePath);

        self::assertSame(1, $serviceMetrics->get('coupling.ca'));
        self::assertSame(2, $serviceMetrics->get('coupling.ce'));
        self::assertEqualsWithDelta(0.666, $serviceMetrics->get('coupling.instability'), 0.01);
    }

    #[Test]
    public function itAggregatesEfferentCouplingAtNamespaceLevel(): void
    {
        // App namespace has 2 classes (Foo, Baz) that depend on Vendor
        // Ce for App = 2 unique external classes (Vendor\Bar, Vendor\Qux)
        $deps = [
            $this->dep('App\\Foo', 'Vendor\\Bar'),
            $this->dep('App\\Baz', 'Vendor\\Qux'),
        ];

        $graph = $this->graph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Foo');
        $this->registerClass($repository, 'App\\Baz');
        $this->registerNamespace($repository, 'App');

        $this->collector->calculate($graph, $repository);

        $appNsPath = SymbolPath::forNamespace('App');
        $appNsMetrics = $repository->get($appNsPath);

        self::assertSame(0, $appNsMetrics->get('coupling.ca'));
        self::assertSame(2, $appNsMetrics->get('coupling.ce'));
        self::assertEqualsWithDelta(1.0, $appNsMetrics->get('coupling.instability'), 0.001);
    }

    #[Test]
    public function itScoresZeroInstabilityForAClassWithOnlyIncomingDependencies(): void
    {
        // A class with no dependencies and no dependents
        // Still appears in the graph as both source and target
        $deps = [
            $this->dep('App\\Foo', 'App\\Bar'),
        ];

        $graph = $this->graph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Foo');
        $this->registerClass($repository, 'App\\Bar');

        $this->collector->calculate($graph, $repository);

        // Bar only appears as target, so Ce = 0
        $barPath = SymbolPath::forClass('App', 'Bar');
        $barMetrics = $repository->get($barPath);

        self::assertSame(1, $barMetrics->get('coupling.ca'));
        self::assertSame(0, $barMetrics->get('coupling.ce'));
        self::assertEqualsWithDelta(0.0, $barMetrics->get('coupling.instability'), 0.001);
    }

    #[Test]
    public function itKeepsDegreeZeroClassAndNamespaceCouplingMetricsExplicit(): void
    {
        $isolated = new LogicalClassPath(SymbolPath::forClass('App\\Isolated', 'Standalone'));
        $graph = $this->graph([], [$isolated]);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Isolated\\Standalone');
        $this->registerNamespace($repository, 'App\\Isolated');

        $this->collector->calculate($graph, $repository);

        $classMetrics = $repository->get(SymbolPath::forClass('App\\Isolated', 'Standalone'));
        self::assertSame(0, $classMetrics->get('coupling.ca'));
        self::assertSame(0, $classMetrics->get('coupling.ce'));
        self::assertSame(0, $classMetrics->get('coupling.cbo'));
        self::assertSame(0.0, $classMetrics->get('coupling.instability'));

        $namespaceMetrics = $repository->get(SymbolPath::forNamespace('App\\Isolated'));
        self::assertSame(0, $namespaceMetrics->get('coupling.ca'));
        self::assertSame(0, $namespaceMetrics->get('coupling.ce'));
        self::assertSame(0, $namespaceMetrics->get('coupling.cbo'));
        self::assertSame(0.0, $namespaceMetrics->get('coupling.instability'));
    }

    #[Test]
    public function itDeduplicatesExactSourcesWhileRetainingExternalLogicalTargets(): void
    {
        $source = SymbolPath::forClass('App', 'Consumer');
        $externalTarget = SymbolPath::forClass('Vendor', 'Gateway');
        $dependencies = [
            new Dependency(
                DeclarationPath::of($source, RelativePath::fromString('src/ConsumerA.php'), DeclarationOrdinal::fromRank(0)),
                new LogicalClassPath($externalTarget),
                DependencyType::New_,
                new Location(RelativePath::fromString('src/ConsumerA.php'), 12),
            ),
            new Dependency(
                DeclarationPath::of($source, RelativePath::fromString('src/ConsumerB.php'), DeclarationOrdinal::fromRank(0)),
                new LogicalClassPath($externalTarget),
                DependencyType::New_,
                new Location(RelativePath::fromString('src/ConsumerB.php'), 22),
            ),
        ];
        $graph = $this->graph($dependencies);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Consumer');

        $this->collector->calculate($graph, $repository);

        $metrics = $repository->get($source);
        self::assertSame(1, $metrics->get('coupling.ce'));
        self::assertSame(1, $metrics->get('coupling.cbo'));
        self::assertSame(1, $metrics->get('coupling.cbo-app'));
        self::assertSame(1, $metrics->get('coupling.ce-packages'));
    }

    #[Test]
    public function itRegistersAGlobalNamespaceClassUnderAnEmptyNamespace(): void
    {
        $deps = [
            $this->dep('GlobalClass', 'Vendor\\Service'),
        ];

        $graph = $this->graph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'GlobalClass');

        $this->collector->calculate($graph, $repository);

        // Global class should be registered with empty namespace
        $globalPath = SymbolPath::forClass('', 'GlobalClass');
        $metrics = $repository->get($globalPath);

        self::assertSame(0, $metrics->get('coupling.ca'));
        self::assertSame(1, $metrics->get('coupling.ce'));
        // Global namespace source (topNs='') depends on Vendor (topNs='Vendor') → 1 package
        self::assertSame(1, $metrics->get('coupling.ce-packages'));
    }

    #[Test]
    public function itComputesCboAsTheUnionOfAfferentAndEfferentDependencies(): void
    {
        // App\Service has Ca = 1 (App\Controller depends on it)
        // App\Service has Ce = 2 (depends on Vendor\A and Vendor\B)
        // CBO = |{Controller, Vendor\A, Vendor\B}| = 3 (no overlap, union = Ca+Ce)
        $deps = [
            $this->dep('App\\Controller', 'App\\Service'),
            $this->dep('App\\Service', 'Vendor\\A'),
            $this->dep('App\\Service', 'Vendor\\B'),
        ];

        $graph = $this->graph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Controller');
        $this->registerClass($repository, 'App\\Service');

        $this->collector->calculate($graph, $repository);

        $servicePath = SymbolPath::forClass('App', 'Service');
        $serviceMetrics = $repository->get($servicePath);

        self::assertSame(1, $serviceMetrics->get('coupling.ca'));
        self::assertSame(2, $serviceMetrics->get('coupling.ce'));
        self::assertSame(3, $serviceMetrics->get('coupling.cbo'));
    }

    #[Test]
    public function itCountsABidirectionalDependencyOnceInCbo(): void
    {
        // A→B and B→A: bidirectional coupling
        // For A: Ca=1 (B depends on A), Ce=1 (A depends on B)
        // CBO(A) = |{B}| = 1 (not Ca+Ce=2, because B appears in both)
        $deps = [
            $this->dep('App\\A', 'App\\B'),
            $this->dep('App\\B', 'App\\A'),
        ];

        $graph = $this->graph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\A');
        $this->registerClass($repository, 'App\\B');

        $this->collector->calculate($graph, $repository);

        $aPath = SymbolPath::forClass('App', 'A');
        $aMetrics = $repository->get($aPath);

        self::assertSame(1, $aMetrics->get('coupling.ca'));
        self::assertSame(1, $aMetrics->get('coupling.ce'));
        // CBO should be 1 (union of {B} and {B}), not 2 (Ca+Ce)
        self::assertSame(1, $aMetrics->get('coupling.cbo'));

        $bPath = SymbolPath::forClass('App', 'B');
        $bMetrics = $repository->get($bPath);

        self::assertSame(1, $bMetrics->get('coupling.ca'));
        self::assertSame(1, $bMetrics->get('coupling.ce'));
        self::assertSame(1, $bMetrics->get('coupling.cbo'));
    }

    #[Test]
    public function itComputesCboFromOnlyIncomingDependenciesWhenThereAreNoOutgoingOnes(): void
    {
        // Class with no dependencies
        $deps = [
            $this->dep('App\\Foo', 'App\\Bar'),
        ];

        $graph = $this->graph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Foo');
        $this->registerClass($repository, 'App\\Bar');

        $this->collector->calculate($graph, $repository);

        // Test Bar which only has incoming dependency
        $barPath = SymbolPath::forClass('App', 'Bar');
        $barMetrics = $repository->get($barPath);

        self::assertSame(1, $barMetrics->get('coupling.ca'));
        self::assertSame(0, $barMetrics->get('coupling.ce'));
        self::assertSame(1, $barMetrics->get('coupling.cbo'));
    }

    #[Test]
    public function itComputesCboAsTheFullUnionForAHighlyCoupledClass(): void
    {
        // App\Service has high coupling
        // Ca = 3 (App\A, App\B, App\C depend on it)
        // Ce = 4 (depends on Vendor\W, Vendor\X, Vendor\Y, Vendor\Z)
        // CBO = |{A, B, C, W, X, Y, Z}| = 7 (no overlap, union = Ca+Ce)
        $deps = [
            $this->dep('App\\A', 'App\\Service'),
            $this->dep('App\\B', 'App\\Service'),
            $this->dep('App\\C', 'App\\Service'),
            $this->dep('App\\Service', 'Vendor\\W'),
            $this->dep('App\\Service', 'Vendor\\X'),
            $this->dep('App\\Service', 'Vendor\\Y'),
            $this->dep('App\\Service', 'Vendor\\Z'),
        ];

        $graph = $this->graph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\A');
        $this->registerClass($repository, 'App\\B');
        $this->registerClass($repository, 'App\\C');
        $this->registerClass($repository, 'App\\Service');

        $this->collector->calculate($graph, $repository);

        $servicePath = SymbolPath::forClass('App', 'Service');
        $serviceMetrics = $repository->get($servicePath);

        self::assertSame(3, $serviceMetrics->get('coupling.ca'));
        self::assertSame(4, $serviceMetrics->get('coupling.ce'));
        self::assertSame(7, $serviceMetrics->get('coupling.cbo'));
    }

    #[Test]
    public function itCountsUniquelyCoupledNamespacesForNamespaceLevelCbo(): void
    {
        // App namespace has Ca = 0, Ce = 2
        // CBO = 2
        $deps = [
            $this->dep('App\\Foo', 'Vendor\\Bar'),
            $this->dep('App\\Baz', 'Vendor\\Qux'),
        ];

        $graph = $this->graph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Foo');
        $this->registerClass($repository, 'App\\Baz');
        $this->registerNamespace($repository, 'App');

        $this->collector->calculate($graph, $repository);

        $appNsPath = SymbolPath::forNamespace('App');
        $appNsMetrics = $repository->get($appNsPath);

        self::assertSame(0, $appNsMetrics->get('coupling.ca'));
        self::assertSame(2, $appNsMetrics->get('coupling.ce'));
        // CBO counts uniquely coupled namespaces (not classes): only Vendor
        self::assertSame(1, $appNsMetrics->get('coupling.cbo'));
    }

    #[Test]
    public function itCountsABidirectionalNamespaceDependencyOnceInNamespaceCbo(): void
    {
        // Namespace A depends on Namespace B (A\Foo -> B\Bar)
        // Namespace B depends on Namespace A (B\Baz -> A\Qux)
        // CBO(A) should be 1 (only namespace B), not 2 (ca + ce)
        $deps = [
            $this->dep('A\\Foo', 'B\\Bar'),
            $this->dep('B\\Baz', 'A\\Qux'),
        ];

        $graph = $this->graph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'A\\Foo');
        $this->registerClass($repository, 'A\\Qux');
        $this->registerClass($repository, 'B\\Bar');
        $this->registerClass($repository, 'B\\Baz');
        $this->registerNamespace($repository, 'A');
        $this->registerNamespace($repository, 'B');

        $this->collector->calculate($graph, $repository);

        $aNsPath = SymbolPath::forNamespace('A');
        $aNsMetrics = $repository->get($aNsPath);

        self::assertSame(1, $aNsMetrics->get('coupling.ca'));
        self::assertSame(1, $aNsMetrics->get('coupling.ce'));
        // CBO should be 1 (union of {B} and {B}), not 2 (ca + ce)
        self::assertSame(1, $aNsMetrics->get('coupling.cbo'));

        $bNsPath = SymbolPath::forNamespace('B');
        $bNsMetrics = $repository->get($bNsPath);

        self::assertSame(1, $bNsMetrics->get('coupling.ca'));
        self::assertSame(1, $bNsMetrics->get('coupling.ce'));
        self::assertSame(1, $bNsMetrics->get('coupling.cbo'));
    }

    /**
     * A parent namespace's Ca and Ce are taken over its whole subtree, so its
     * CBO has to be taken over the same region: the namespaces on the far side
     * of an edge that leaves or enters the subtree. Keyed by the namespace
     * string alone, the parent matched no edge and published 0 beside a
     * non-zero Ce, and a class declared in the parent itself counted its own
     * sub-namespace as external.
     */
    #[Test]
    public function itCountsAParentNamespaceCboOverTheSameSubtreeAsItsCaAndCe(): void
    {
        $deps = [
            $this->dep('App\\A\\X', 'Ext\\Z'),
            $this->dep('App\\B\\Y', 'Ext2\\Q'),
            $this->dep('App\\Foo', 'App\\A\\X'),
            $this->dep('Ext\\P', 'App\\B\\Y'),
        ];

        $graph = $this->realGraph($deps);
        $repository = new InMemoryMetricRepository();
        foreach (['App\\A\\X', 'App\\B\\Y', 'App\\Foo', 'Ext\\P'] as $class) {
            $this->registerClass($repository, $class);
        }
        foreach (['App', 'App\\A', 'App\\B', 'Ext'] as $namespace) {
            $this->registerNamespace($repository, $namespace);
        }

        $this->collector->calculate($graph, $repository);

        $app = $repository->get(SymbolPath::forNamespace('App'));
        self::assertSame(2, $app->get('coupling.ce'));
        self::assertSame(1, $app->get('coupling.ca'));
        // Ext (both directions) and Ext2; App\A is inside App, not coupled to it.
        self::assertSame(2, $app->get('coupling.cbo'));

        // A leaf keeps its own answer: Ext out, and App, where App\Foo lives, in.
        self::assertSame(2, $repository->get(SymbolPath::forNamespace('App\\A'))->get('coupling.cbo'));
        // Ext2 out, Ext in: one namespace each way.
        self::assertSame(2, $repository->get(SymbolPath::forNamespace('App\\B'))->get('coupling.cbo'));
    }

    /**
     * Extending a PHP class is how a class names PHP's type, not coupling to
     * it: the same class written as `implements \Countable` or typed on
     * `\DateTimeImmutable` counts nothing, so neither may `extends`. Every
     * coupling number read from the class's edges agrees.
     */
    #[Test]
    public function itCountsNoCouplingForExtendingAPhpClass(): void
    {
        $deps = [
            $this->dep('App\\MyErr', 'RuntimeException', DependencyType::Extends),
            $this->dep('App\\Rand', 'Random\\RandomException', DependencyType::Extends),
            $this->dep('App\\Child', 'Vendor\\Base', DependencyType::Extends),
        ];

        $graph = $this->realGraph($deps);
        $repository = new InMemoryMetricRepository();
        foreach (['App\\MyErr', 'App\\Rand', 'App\\Child'] as $class) {
            $this->registerClass($repository, $class);
        }
        $this->registerNamespace($repository, 'App');

        $this->collector->calculate($graph, $repository);

        foreach (['App\\MyErr', 'App\\Rand'] as $class) {
            $metrics = $repository->get(SymbolPath::fromClassFqn($class));
            foreach (['coupling.ce', 'coupling.cbo', 'coupling.cbo-app', 'coupling.ce-packages'] as $key) {
                self::assertSame(0, $metrics->get($key), $class . ' ' . $key);
            }
        }

        // The neighbour: extending a vendor class is coupling.
        $child = $repository->get(SymbolPath::fromClassFqn('App\\Child'));
        self::assertSame(1, $child->get('coupling.ce'));
        self::assertSame(1, $child->get('coupling.cbo'));

        $namespace = $repository->get(SymbolPath::forNamespace('App'));
        self::assertSame(1, $namespace->get('coupling.ce'));
        self::assertSame(1, $namespace->get('coupling.cbo'));
    }

    /**
     * Namespace CBO counts namespaces and Ca/Ce count classes, so the numbers
     * differ -- but over one region they cannot disagree about whether there
     * is any coupling at all, nor can CBO exceed the classes it is drawn from.
     */
    #[Test]
    public function itKeepsEveryNamespaceCboWithinTheCouplingItsCaAndCeMeasure(): void
    {
        $deps = [
            $this->dep('App\\A\\X', 'Ext\\Z'),
            $this->dep('App\\A\\X', 'App\\B\\Y'),
            $this->dep('App\\B\\Y', 'App\\A\\W'),
            $this->dep('App\\Foo', 'App\\A\\X'),
            $this->dep('App\\C\\D\\E', 'App\\C\\F'),
            $this->dep('Ext\\P', 'App\\C\\F'),
            $this->dep('Other\\Iso', 'Other\\Iso2'),
        ];

        $graph = $this->realGraph($deps);
        $repository = new InMemoryMetricRepository();
        foreach ($graph->getAllClasses() as $class) {
            $repository->add($class, new MetricBag(), RelativePath::fromString('test.php'), 1);
        }
        foreach ($graph->getAllNamespaces() as $namespace) {
            $repository->add($namespace, new MetricBag(), RelativePath::fromString('test.php'), null);
        }

        $this->collector->calculate($graph, $repository);

        foreach ($graph->getAllNamespaces() as $namespace) {
            $metrics = $repository->get($namespace);
            $classes = (int) $metrics->get('coupling.ca') + (int) $metrics->get('coupling.ce');
            $cbo = (int) $metrics->get('coupling.cbo');

            self::assertSame($classes === 0, $cbo === 0, $namespace->toString());
            self::assertLessThanOrEqual($classes, $cbo, $namespace->toString());
        }
    }

    /**
     * A namespace that both declares classes and contains a sub-namespace gets
     * two answers, and they have to be told apart: the subtree rollup counts
     * the sub-namespace's crossings as its own, the own scope counts only what
     * the namespace itself declares. Instability is published for both, so a
     * fixture where the two ratios differ is what keeps them apart.
     */
    #[Test]
    public function itPublishesTheOwnScopeOfANamespaceBesideItsSubtreeRollup(): void
    {
        $deps = [
            $this->dep('A\\B\\X', 'Ext\\Y'),
            $this->dep('A\\Z', 'Ext\\W'),
            $this->dep('Ext\\P', 'A\\Z'),
        ];

        $graph = $this->realGraph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'A\\Z');
        $this->registerClass($repository, 'A\\B\\X');
        $this->registerNamespace($repository, 'A');
        $this->registerNamespace($repository, 'A\\B');

        $this->collector->calculate($graph, $repository);
        $metrics = $repository->get(SymbolPath::forNamespace('A'));

        self::assertSame(2, $metrics->get('coupling.ce'));
        self::assertSame(1, $metrics->get('coupling.ca'));
        self::assertEqualsWithDelta(2 / 3, $metrics->get('coupling.instability'), 0.0001);

        self::assertSame(1, $metrics->get('coupling.ce-own'));
        self::assertSame(1, $metrics->get('coupling.ca-own'));
        self::assertEqualsWithDelta(0.5, $metrics->get('coupling.instability-own'), 0.0001);
    }

    /**
     * A namespace without sub-namespaces has one scope, and both spellings must
     * report it -- otherwise the own key would be a second, quieter metric
     * rather than the same measurement taken over exactly this namespace.
     */
    #[Test]
    public function itReportsTheSameCouplingInBothScopesForANamespaceWithoutChildren(): void
    {
        $deps = [
            $this->dep('A\\Foo', 'B\\Bar'),
            $this->dep('B\\Baz', 'A\\Qux'),
        ];

        $graph = $this->realGraph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'A\\Foo');
        $this->registerClass($repository, 'A\\Qux');
        $this->registerNamespace($repository, 'A');

        $this->collector->calculate($graph, $repository);
        $metrics = $repository->get(SymbolPath::forNamespace('A'));

        self::assertSame($metrics->get('coupling.ce'), $metrics->get('coupling.ce-own'));
        self::assertSame($metrics->get('coupling.ca'), $metrics->get('coupling.ca-own'));
        self::assertSame($metrics->get('coupling.instability'), $metrics->get('coupling.instability-own'));
        self::assertSame(1, $metrics->get('coupling.ce-own'));
    }

    #[Test]
    public function itDoesNotRegisterASymbolForAnExternalDependencyClass(): void
    {
        // App\Foo depends on Vendor\Bar — but only App\Foo is a project class
        $deps = [
            $this->dep('App\\Foo', 'Vendor\\Bar'),
        ];

        $graph = $this->graph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Foo');

        $this->collector->calculate($graph, $repository);

        // Vendor\Bar must NOT be added to the repository
        $vendorPath = SymbolPath::forClass('Vendor', 'Bar');
        self::assertFalse(
            $repository->has($vendorPath),
            'Global collectors must not create symbols for external classes (see GlobalContextCollectorInterface::calculate() contract)',
        );
    }

    #[Test]
    public function itDoesNotRegisterASymbolForAnExternalDependencyNamespace(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'Vendor\\Bar'),
        ];

        $graph = $this->graph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Foo');
        $this->registerNamespace($repository, 'App');

        $this->collector->calculate($graph, $repository);

        // Vendor namespace must NOT be added to the repository
        $vendorNsPath = SymbolPath::forNamespace('Vendor');
        self::assertFalse(
            $repository->has($vendorNsPath),
            'Global collectors must not create symbols for external namespaces (see GlobalContextCollectorInterface::calculate() contract)',
        );
    }

    #[Test]
    public function itCountsEachDistinctTopLevelPackageInCePackages(): void
    {
        // App\Foo depends on PhpParser\Node, PhpParser\Lexer, Symfony\Console, Psr\Log
        // Top-level namespaces: PhpParser, Symfony, Psr → ce_packages = 3
        $deps = [
            $this->dep('App\\Foo', 'PhpParser\\Node'),
            $this->dep('App\\Foo', 'PhpParser\\Lexer'),
            $this->dep('App\\Foo', 'Symfony\\Console'),
            $this->dep('App\\Foo', 'Psr\\Log'),
        ];

        $graph = $this->graph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Foo');

        $this->collector->calculate($graph, $repository);

        $fooPath = SymbolPath::forClass('App', 'Foo');
        $fooMetrics = $repository->get($fooPath);

        self::assertSame(3, $fooMetrics->get('coupling.ce-packages'));
    }

    #[Test]
    public function itCountsOnlyOnePackageWhenAllDependenciesShareATopLevelNamespace(): void
    {
        // All deps from same top-level namespace (PhpParser) → ce_packages = 1
        $deps = [
            $this->dep('App\\Service\\Foo', 'PhpParser\\Node\\Expr'),
            $this->dep('App\\Service\\Foo', 'PhpParser\\Node\\Stmt'),
            $this->dep('App\\Service\\Foo', 'PhpParser\\Lexer'),
        ];

        $graph = $this->graph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Service\\Foo');

        $this->collector->calculate($graph, $repository);

        $fooPath = SymbolPath::forClass('App\\Service', 'Foo');
        $fooMetrics = $repository->get($fooPath);

        self::assertSame(1, $fooMetrics->get('coupling.ce-packages'));
    }

    #[Test]
    public function itExcludesIntraPackageDependenciesFromCePackages(): void
    {
        // All deps within same top-level namespace (App) → ce_packages = 0
        $deps = [
            $this->dep('App\\Service\\Foo', 'App\\Repository\\Bar'),
            $this->dep('App\\Service\\Foo', 'App\\Model\\Baz'),
        ];

        $graph = $this->graph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Service\\Foo');
        $this->registerClass($repository, 'App\\Repository\\Bar');
        $this->registerClass($repository, 'App\\Model\\Baz');

        $this->collector->calculate($graph, $repository);

        $fooPath = SymbolPath::forClass('App\\Service', 'Foo');
        $fooMetrics = $repository->get($fooPath);

        self::assertSame(0, $fooMetrics->get('coupling.ce-packages'));
    }

    #[Test]
    public function itScoresZeroCePackagesForAClassWithOnlyIncomingDependencies(): void
    {
        // App\Bar has only afferent deps (no Ce) → ce_packages = 0
        $deps = [
            $this->dep('App\\Foo', 'App\\Bar'),
        ];

        $graph = $this->graph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Foo');
        $this->registerClass($repository, 'App\\Bar');

        $this->collector->calculate($graph, $repository);

        $barPath = SymbolPath::forClass('App', 'Bar');
        $barMetrics = $repository->get($barPath);

        self::assertSame(0, $barMetrics->get('coupling.ce-packages'));
    }

    // Framework CBO tests

    #[Test]
    public function itExcludesFrameworkDependenciesFromCboAppWithNoIncomingAppDependency(): void
    {
        // Configure framework namespaces
        $collector = new CouplingCollector($this->configuredAnalysis(['Symfony', 'PhpParser', 'Psr']));

        // App\Service depends on Symfony\Console, PhpParser\Node, App\Repository
        $deps = [
            $this->dep('App\\Service', 'Symfony\\Component\\Console'),
            $this->dep('App\\Service', 'PhpParser\\Node'),
            $this->dep('App\\Service', 'App\\Repository'),
            $this->dep('App\\Controller', 'App\\Service'),
        ];

        $graph = $this->graph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Service');
        $this->registerClass($repository, 'App\\Repository');
        $this->registerClass($repository, 'App\\Controller');

        $collector->calculate($graph, $repository);

        $servicePath = SymbolPath::forClass('App', 'Service');
        $serviceMetrics = $repository->get($servicePath);

        // CBO = |{Symfony\Console, PhpParser\Node, App\Repository, App\Controller}| = 4
        self::assertSame(4, $serviceMetrics->get('coupling.cbo'));
        // CBO_APP = |{App\Repository, App\Controller}| = 2 (framework deps excluded)
        self::assertSame(2, $serviceMetrics->get('coupling.cbo-app'));
        // CE_FRAMEWORK = 2 (Symfony\Console, PhpParser\Node)
        self::assertSame(2, $serviceMetrics->get('coupling.ce-framework'));
    }

    #[Test]
    public function itMakesCboAppEqualCboWhenNoFrameworkNamespacesAreConfigured(): void
    {
        // No framework namespaces configured (default)
        $deps = [
            $this->dep('App\\Service', 'Symfony\\Console'),
            $this->dep('App\\Service', 'App\\Repository'),
        ];

        $graph = $this->graph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Service');
        $this->registerClass($repository, 'App\\Repository');

        $this->collector->calculate($graph, $repository);

        $servicePath = SymbolPath::forClass('App', 'Service');
        $serviceMetrics = $repository->get($servicePath);

        // When no framework namespaces configured, CBO_APP = CBO
        self::assertSame($serviceMetrics->get('coupling.cbo'), $serviceMetrics->get('coupling.cbo-app'));
        self::assertSame(0, $serviceMetrics->get('coupling.ce-framework'));
    }

    #[Test]
    public function itCountsOnlyEfferentFrameworkDependenciesInCeFramework(): void
    {
        $collector = new CouplingCollector($this->configuredAnalysis(['Symfony']));

        // App\Service depends on 3 Symfony classes
        $deps = [
            $this->dep('App\\Service', 'Symfony\\Console\\Command'),
            $this->dep('App\\Service', 'Symfony\\HttpFoundation\\Request'),
            $this->dep('App\\Service', 'Symfony\\DI\\Container'),
            $this->dep('App\\Service', 'App\\Repository'),
        ];

        $graph = $this->graph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Service');
        $this->registerClass($repository, 'App\\Repository');

        $collector->calculate($graph, $repository);

        $servicePath = SymbolPath::forClass('App', 'Service');
        $serviceMetrics = $repository->get($servicePath);

        self::assertSame(3, $serviceMetrics->get('coupling.ce-framework'));
    }

    #[Test]
    public function itMatchesFrameworkPrefixesOnNamespaceBoundariesWhenClassifyingDependencies(): void
    {
        $collector = new CouplingCollector($this->configuredAnalysis(['Psr']));

        // Psr\Log should match, PsrExtended\Custom should NOT match
        $deps = [
            $this->dep('App\\Service', 'Psr\\Log\\LoggerInterface'),
            $this->dep('App\\Service', 'PsrExtended\\Custom\\Class_'),
        ];

        $graph = $this->graph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Service');

        $collector->calculate($graph, $repository);

        $servicePath = SymbolPath::forClass('App', 'Service');
        $serviceMetrics = $repository->get($servicePath);

        // CBO = 2 (both deps), CBO_APP = 1 (only PsrExtended), CE_FRAMEWORK = 1 (only Psr\Log)
        self::assertSame(2, $serviceMetrics->get('coupling.cbo'));
        self::assertSame(1, $serviceMetrics->get('coupling.cbo-app'));
        self::assertSame(1, $serviceMetrics->get('coupling.ce-framework'));
    }

    #[Test]
    public function itPartitionsCeIntoCeAppAndCeFrameworkWithoutOverlap(): void
    {
        // Verify: Ce = Ce_app + Ce_framework (outgoing dependencies partition cleanly)
        $collector = new CouplingCollector($this->configuredAnalysis(['Symfony', 'PhpParser']));

        $deps = [
            $this->dep('App\\Service', 'Symfony\\Console'),
            $this->dep('App\\Service', 'PhpParser\\Node'),
            $this->dep('App\\Service', 'App\\Repository'),
            $this->dep('App\\Service', 'App\\Model'),
        ];

        $graph = $this->graph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Service');
        $this->registerClass($repository, 'App\\Repository');
        $this->registerClass($repository, 'App\\Model');

        $collector->calculate($graph, $repository);

        $servicePath = SymbolPath::forClass('App', 'Service');
        $serviceMetrics = $repository->get($servicePath);

        $ce = $serviceMetrics->get('coupling.ce');
        $ceFramework = $serviceMetrics->get('coupling.ce-framework');
        // Ce_app (non-framework efferent) = Ce - Ce_framework
        self::assertSame(4, $ce);
        self::assertSame(2, $ceFramework);
        // Note: CBO_APP also includes afferent app deps, so it's not just Ce - Ce_framework
    }

    #[Test]
    public function itExcludesFrameworkDependenciesFromCboAppWithAnIncomingAppDependency(): void
    {
        // A→FrameworkClass — Ce_framework=1, but framework is not scanned
        // so Ca from framework doesn't exist. CBO_APP should exclude framework.
        $collector = new CouplingCollector($this->configuredAnalysis(['Symfony']));

        $deps = [
            $this->dep('App\\Service', 'Symfony\\Console'),
            $this->dep('App\\Other', 'App\\Service'),
        ];

        $graph = $this->graph($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Service');
        $this->registerClass($repository, 'App\\Other');

        $collector->calculate($graph, $repository);

        $servicePath = SymbolPath::forClass('App', 'Service');
        $serviceMetrics = $repository->get($servicePath);

        // CBO = |{Symfony\Console, App\Other}| = 2
        self::assertSame(2, $serviceMetrics->get('coupling.cbo'));
        // CBO_APP = |{App\Other}| = 1 (framework excluded)
        self::assertSame(1, $serviceMetrics->get('coupling.cbo-app'));
        self::assertSame(1, $serviceMetrics->get('coupling.ce-framework'));
    }

    private function dep(string $source, string $target, DependencyType $type = DependencyType::New_): Dependency
    {
        return new Dependency(
            DeclarationPath::of(SymbolPath::fromClassFqn($source), RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0)),
            new LogicalClassPath(SymbolPath::fromClassFqn($target)),
            $type,
            new Location(RelativePath::fromString('test.php'), 1),
        );
    }

    /**
     * The graph the product builds, rather than the adjacency double the rest
     * of this file uses: the double knows no parent namespaces, so a subtree
     * rollup and an own scope are the same number in it and a case about their
     * difference would pass on a graph that never had one.
     *
     * @param list<Dependency> $dependencies
     */
    private function realGraph(array $dependencies): DependencyGraphInterface
    {
        return (new DependencyGraphBuilder())->build($dependencies, array_map(
            static fn(Dependency $dependency): LogicalClassPath => new LogicalClassPath($dependency->sourceLogical()),
            $dependencies,
        ));
    }

    /**
     * @param list<Dependency> $dependencies
     * @param list<LogicalClassPath> $universe
     */
    private function graph(array $dependencies, array $universe = []): DependencyGraphInterface
    {
        if ($universe === []) {
            $universe = array_map(
                static fn(Dependency $dependency): LogicalClassPath => new LogicalClassPath($dependency->sourceLogical()),
                $dependencies,
            );
        }

        return $this->graphBuilder->build($dependencies, $universe);
    }

    private function registerClass(InMemoryMetricRepository $repository, string $fqn): void
    {
        $repository->add(SymbolPath::fromClassFqn($fqn), new MetricBag(), RelativePath::fromString('test.php'), 1);
    }

    private function registerNamespace(InMemoryMetricRepository $repository, string $namespace): void
    {
        $repository->add(SymbolPath::forNamespace($namespace), new MetricBag(), RelativePath::fromString('test.php'), null);
    }

    /** @param list<string> $prefixes */
    private function configuredAnalysis(array $prefixes): CouplingAnalysis
    {
        $analysis = new CouplingAnalysis();
        $analysis->replace($analysis->resolve($this->document([
            ['coupling' => ['frameworkNamespaces' => array_map(static fn(string $prefix): array => ['subtree' => $prefix], $prefixes)]],
        ])));

        return $analysis;
    }

    /** @param list<array<string, mixed>> $contributions */
    private function document(array $contributions): ConfigurationDocument
    {
        return new ConfigurationDocument(array_map(
            static fn(array $values): array => ['source' => 'test', 'values' => $values],
            $contributions,
        ), AbsolutePath::fromString('/project'));
    }
}
