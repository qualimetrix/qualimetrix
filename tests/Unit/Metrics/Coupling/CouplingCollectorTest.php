<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Coupling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Collection\Dependency\DependencyGraphBuilder;
use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Coupling\FrameworkNamespaces;
use Qualimetrix\Core\Coupling\FrameworkNamespacesHolder;
use Qualimetrix\Core\Dependency\Dependency;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Metrics\Coupling\CouplingCollector;

#[CoversClass(CouplingCollector::class)]
final class CouplingCollectorTest extends TestCase
{
    private CouplingCollector $collector;
    private DependencyGraphBuilder $graphBuilder;

    protected function setUp(): void
    {
        $this->collector = new CouplingCollector(new FrameworkNamespacesHolder());
        $this->graphBuilder = new DependencyGraphBuilder();
    }

    #[Test]
    public function getName_returnsCoupling(): void
    {
        self::assertSame('coupling', $this->collector->getName());
    }

    #[Test]
    public function requires_returnsEmptyArray(): void
    {
        self::assertSame([], $this->collector->requires());
    }

    #[Test]
    public function provides_returnsCouplingMetrics(): void
    {
        self::assertSame(['ca', 'ce', 'cbo', 'instability', 'ce_packages', 'cbo_app', 'ce_framework'], $this->collector->provides());
    }

    #[Test]
    public function getMetricDefinitions_returnsSevenDefinitions(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(7, $definitions);

        // ca metric
        $ca = $definitions[0];
        self::assertSame('ca', $ca->name);
        self::assertSame(SymbolLevel::Class_, $ca->collectedAt);
        self::assertSame(
            [AggregationStrategy::Sum],
            $ca->getStrategiesForLevel(SymbolLevel::Namespace_),
        );
        self::assertSame([], $ca->getStrategiesForLevel(SymbolLevel::Project));

        // ce metric
        $ce = $definitions[1];
        self::assertSame('ce', $ce->name);
        self::assertSame(SymbolLevel::Class_, $ce->collectedAt);
        self::assertSame(
            [AggregationStrategy::Sum],
            $ce->getStrategiesForLevel(SymbolLevel::Namespace_),
        );
        self::assertSame([], $ce->getStrategiesForLevel(SymbolLevel::Project));

        // cbo metric
        $cbo = $definitions[2];
        self::assertSame('cbo', $cbo->name);
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
        self::assertSame('instability', $instability->name);
        self::assertSame(SymbolLevel::Class_, $instability->collectedAt);
        self::assertSame(
            [AggregationStrategy::Average],
            $instability->getStrategiesForLevel(SymbolLevel::Namespace_),
        );
        self::assertSame([], $instability->getStrategiesForLevel(SymbolLevel::Project));

        // ce_packages metric
        $cePackages = $definitions[4];
        self::assertSame('ce_packages', $cePackages->name);
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
        self::assertSame('cbo_app', $cboApp->name);
        self::assertSame(SymbolLevel::Class_, $cboApp->collectedAt);
        self::assertSame(
            [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max, AggregationStrategy::Percentile95],
            $cboApp->getStrategiesForLevel(SymbolLevel::Namespace_),
        );
        self::assertSame(
            [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max, AggregationStrategy::Percentile95],
            $cboApp->getStrategiesForLevel(SymbolLevel::Project),
        );

        // ce_framework metric
        $ceFramework = $definitions[6];
        self::assertSame('ce_framework', $ceFramework->name);
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
    public function calculate_computesClassMetrics(): void
    {
        // App\Foo depends on Vendor\Bar and Vendor\Baz (Ce = 2)
        // Nothing depends on App\Foo (Ca = 0)
        $deps = [
            $this->dep('App\\Foo', 'Vendor\\Bar'),
            $this->dep('App\\Foo', 'Vendor\\Baz'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Foo');

        $this->collector->calculate($graph, $repository);

        $fooPath = SymbolPath::forClass('App', 'Foo');
        $fooMetrics = $repository->get($fooPath);

        self::assertSame(0, $fooMetrics->get('ca'));
        self::assertSame(2, $fooMetrics->get('ce'));
        self::assertEqualsWithDelta(1.0, $fooMetrics->get('instability'), 0.001);
    }

    #[Test]
    public function calculate_computesAfferentCoupling(): void
    {
        // Both App\Foo and App\Baz depend on App\Bar
        // App\Bar has Ca = 2
        $deps = [
            $this->dep('App\\Foo', 'App\\Bar'),
            $this->dep('App\\Baz', 'App\\Bar'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Foo');
        $this->registerClass($repository, 'App\\Baz');
        $this->registerClass($repository, 'App\\Bar');

        $this->collector->calculate($graph, $repository);

        $barPath = SymbolPath::forClass('App', 'Bar');
        $barMetrics = $repository->get($barPath);

        self::assertSame(2, $barMetrics->get('ca'));
        self::assertSame(0, $barMetrics->get('ce'));
        self::assertEqualsWithDelta(0.0, $barMetrics->get('instability'), 0.001);
    }

    #[Test]
    public function calculate_computesInstability(): void
    {
        // App\Service has Ca = 1 (App\Controller depends on it)
        // App\Service has Ce = 2 (depends on Vendor\A and Vendor\B)
        // Instability = 2 / (1 + 2) = 0.666...
        $deps = [
            $this->dep('App\\Controller', 'App\\Service'),
            $this->dep('App\\Service', 'Vendor\\A'),
            $this->dep('App\\Service', 'Vendor\\B'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Controller');
        $this->registerClass($repository, 'App\\Service');

        $this->collector->calculate($graph, $repository);

        $servicePath = SymbolPath::forClass('App', 'Service');
        $serviceMetrics = $repository->get($servicePath);

        self::assertSame(1, $serviceMetrics->get('ca'));
        self::assertSame(2, $serviceMetrics->get('ce'));
        self::assertEqualsWithDelta(0.666, $serviceMetrics->get('instability'), 0.01);
    }

    #[Test]
    public function calculate_computesNamespaceMetrics(): void
    {
        // App namespace has 2 classes (Foo, Baz) that depend on Vendor
        // Ce for App = 2 unique external classes (Vendor\Bar, Vendor\Qux)
        $deps = [
            $this->dep('App\\Foo', 'Vendor\\Bar'),
            $this->dep('App\\Baz', 'Vendor\\Qux'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Foo');
        $this->registerClass($repository, 'App\\Baz');
        $this->registerNamespace($repository, 'App');

        $this->collector->calculate($graph, $repository);

        $appNsPath = SymbolPath::forNamespace('App');
        $appNsMetrics = $repository->get($appNsPath);

        self::assertSame(0, $appNsMetrics->get('ca'));
        self::assertSame(2, $appNsMetrics->get('ce'));
        self::assertEqualsWithDelta(1.0, $appNsMetrics->get('instability'), 0.001);
    }

    #[Test]
    public function calculate_isolatedClass_hasZeroInstability(): void
    {
        // A class with no dependencies and no dependents
        // Still appears in the graph as both source and target
        $deps = [
            $this->dep('App\\Foo', 'App\\Bar'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Foo');
        $this->registerClass($repository, 'App\\Bar');

        $this->collector->calculate($graph, $repository);

        // Bar only appears as target, so Ce = 0
        $barPath = SymbolPath::forClass('App', 'Bar');
        $barMetrics = $repository->get($barPath);

        self::assertSame(1, $barMetrics->get('ca'));
        self::assertSame(0, $barMetrics->get('ce'));
        self::assertEqualsWithDelta(0.0, $barMetrics->get('instability'), 0.001);
    }

    #[Test]
    public function calculate_handlesGlobalNamespaceClasses(): void
    {
        $deps = [
            $this->dep('GlobalClass', 'Vendor\\Service'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'GlobalClass');

        $this->collector->calculate($graph, $repository);

        // Global class should be registered with empty namespace
        $globalPath = SymbolPath::forClass('', 'GlobalClass');
        $metrics = $repository->get($globalPath);

        self::assertSame(0, $metrics->get('ca'));
        self::assertSame(1, $metrics->get('ce'));
        // Global namespace source (topNs='') depends on Vendor (topNs='Vendor') → 1 package
        self::assertSame(1, $metrics->get('ce_packages'));
    }

    #[Test]
    public function calculate_computesCbo(): void
    {
        // App\Service has Ca = 1 (App\Controller depends on it)
        // App\Service has Ce = 2 (depends on Vendor\A and Vendor\B)
        // CBO = |{Controller, Vendor\A, Vendor\B}| = 3 (no overlap, union = Ca+Ce)
        $deps = [
            $this->dep('App\\Controller', 'App\\Service'),
            $this->dep('App\\Service', 'Vendor\\A'),
            $this->dep('App\\Service', 'Vendor\\B'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Controller');
        $this->registerClass($repository, 'App\\Service');

        $this->collector->calculate($graph, $repository);

        $servicePath = SymbolPath::forClass('App', 'Service');
        $serviceMetrics = $repository->get($servicePath);

        self::assertSame(1, $serviceMetrics->get('ca'));
        self::assertSame(2, $serviceMetrics->get('ce'));
        self::assertSame(3, $serviceMetrics->get('cbo'));
    }

    #[Test]
    public function calculate_cboBidirectionalCoupling_countsUnion(): void
    {
        // A→B and B→A: bidirectional coupling
        // For A: Ca=1 (B depends on A), Ce=1 (A depends on B)
        // CBO(A) = |{B}| = 1 (not Ca+Ce=2, because B appears in both)
        $deps = [
            $this->dep('App\\A', 'App\\B'),
            $this->dep('App\\B', 'App\\A'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\A');
        $this->registerClass($repository, 'App\\B');

        $this->collector->calculate($graph, $repository);

        $aPath = SymbolPath::forClass('App', 'A');
        $aMetrics = $repository->get($aPath);

        self::assertSame(1, $aMetrics->get('ca'));
        self::assertSame(1, $aMetrics->get('ce'));
        // CBO should be 1 (union of {B} and {B}), not 2 (Ca+Ce)
        self::assertSame(1, $aMetrics->get('cbo'));

        $bPath = SymbolPath::forClass('App', 'B');
        $bMetrics = $repository->get($bPath);

        self::assertSame(1, $bMetrics->get('ca'));
        self::assertSame(1, $bMetrics->get('ce'));
        self::assertSame(1, $bMetrics->get('cbo'));
    }

    #[Test]
    public function calculate_cboZeroForIsolatedClass(): void
    {
        // Class with no dependencies
        $deps = [
            $this->dep('App\\Foo', 'App\\Bar'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Foo');
        $this->registerClass($repository, 'App\\Bar');

        $this->collector->calculate($graph, $repository);

        // Test Bar which only has incoming dependency
        $barPath = SymbolPath::forClass('App', 'Bar');
        $barMetrics = $repository->get($barPath);

        self::assertSame(1, $barMetrics->get('ca'));
        self::assertSame(0, $barMetrics->get('ce'));
        self::assertSame(1, $barMetrics->get('cbo'));
    }

    #[Test]
    public function calculate_cboHighForHighlyCoupledClass(): void
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

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\A');
        $this->registerClass($repository, 'App\\B');
        $this->registerClass($repository, 'App\\C');
        $this->registerClass($repository, 'App\\Service');

        $this->collector->calculate($graph, $repository);

        $servicePath = SymbolPath::forClass('App', 'Service');
        $serviceMetrics = $repository->get($servicePath);

        self::assertSame(3, $serviceMetrics->get('ca'));
        self::assertSame(4, $serviceMetrics->get('ce'));
        self::assertSame(7, $serviceMetrics->get('cbo'));
    }

    #[Test]
    public function calculate_computesCboForNamespace(): void
    {
        // App namespace has Ca = 0, Ce = 2
        // CBO = 2
        $deps = [
            $this->dep('App\\Foo', 'Vendor\\Bar'),
            $this->dep('App\\Baz', 'Vendor\\Qux'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Foo');
        $this->registerClass($repository, 'App\\Baz');
        $this->registerNamespace($repository, 'App');

        $this->collector->calculate($graph, $repository);

        $appNsPath = SymbolPath::forNamespace('App');
        $appNsMetrics = $repository->get($appNsPath);

        self::assertSame(0, $appNsMetrics->get('ca'));
        self::assertSame(2, $appNsMetrics->get('ce'));
        // CBO counts uniquely coupled namespaces (not classes): only Vendor
        self::assertSame(1, $appNsMetrics->get('cbo'));
    }

    #[Test]
    public function calculate_namespaceCboBidirectional_countsUnion(): void
    {
        // Namespace A depends on Namespace B (A\Foo -> B\Bar)
        // Namespace B depends on Namespace A (B\Baz -> A\Qux)
        // CBO(A) should be 1 (only namespace B), not 2 (ca + ce)
        $deps = [
            $this->dep('A\\Foo', 'B\\Bar'),
            $this->dep('B\\Baz', 'A\\Qux'),
        ];

        $graph = $this->graphBuilder->build($deps);
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

        self::assertSame(1, $aNsMetrics->get('ca'));
        self::assertSame(1, $aNsMetrics->get('ce'));
        // CBO should be 1 (union of {B} and {B}), not 2 (ca + ce)
        self::assertSame(1, $aNsMetrics->get('cbo'));

        $bNsPath = SymbolPath::forNamespace('B');
        $bNsMetrics = $repository->get($bNsPath);

        self::assertSame(1, $bNsMetrics->get('ca'));
        self::assertSame(1, $bNsMetrics->get('ce'));
        self::assertSame(1, $bNsMetrics->get('cbo'));
    }

    #[Test]
    public function calculate_doesNotCreateSymbolsForExternalClasses(): void
    {
        // App\Foo depends on Vendor\Bar — but only App\Foo is a project class
        $deps = [
            $this->dep('App\\Foo', 'Vendor\\Bar'),
        ];

        $graph = $this->graphBuilder->build($deps);
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
    public function calculate_doesNotCreateSymbolsForExternalNamespaces(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'Vendor\\Bar'),
        ];

        $graph = $this->graphBuilder->build($deps);
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
    public function calculate_computesCePackages_multiplePackages(): void
    {
        // App\Foo depends on PhpParser\Node, PhpParser\Lexer, Symfony\Console, Psr\Log
        // Top-level namespaces: PhpParser, Symfony, Psr → ce_packages = 3
        $deps = [
            $this->dep('App\\Foo', 'PhpParser\\Node'),
            $this->dep('App\\Foo', 'PhpParser\\Lexer'),
            $this->dep('App\\Foo', 'Symfony\\Console'),
            $this->dep('App\\Foo', 'Psr\\Log'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Foo');

        $this->collector->calculate($graph, $repository);

        $fooPath = SymbolPath::forClass('App', 'Foo');
        $fooMetrics = $repository->get($fooPath);

        self::assertSame(3, $fooMetrics->get('ce_packages'));
    }

    #[Test]
    public function calculate_computesCePackages_samePackage(): void
    {
        // All deps from same top-level namespace (PhpParser) → ce_packages = 1
        $deps = [
            $this->dep('App\\Service\\Foo', 'PhpParser\\Node\\Expr'),
            $this->dep('App\\Service\\Foo', 'PhpParser\\Node\\Stmt'),
            $this->dep('App\\Service\\Foo', 'PhpParser\\Lexer'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Service\\Foo');

        $this->collector->calculate($graph, $repository);

        $fooPath = SymbolPath::forClass('App\\Service', 'Foo');
        $fooMetrics = $repository->get($fooPath);

        self::assertSame(1, $fooMetrics->get('ce_packages'));
    }

    #[Test]
    public function calculate_computesCePackages_intraPackageDepsExcluded(): void
    {
        // All deps within same top-level namespace (App) → ce_packages = 0
        $deps = [
            $this->dep('App\\Service\\Foo', 'App\\Repository\\Bar'),
            $this->dep('App\\Service\\Foo', 'App\\Model\\Baz'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Service\\Foo');
        $this->registerClass($repository, 'App\\Repository\\Bar');
        $this->registerClass($repository, 'App\\Model\\Baz');

        $this->collector->calculate($graph, $repository);

        $fooPath = SymbolPath::forClass('App\\Service', 'Foo');
        $fooMetrics = $repository->get($fooPath);

        self::assertSame(0, $fooMetrics->get('ce_packages'));
    }

    #[Test]
    public function calculate_computesCePackages_onlyIncomingDeps(): void
    {
        // App\Bar has only afferent deps (no Ce) → ce_packages = 0
        $deps = [
            $this->dep('App\\Foo', 'App\\Bar'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Foo');
        $this->registerClass($repository, 'App\\Bar');

        $this->collector->calculate($graph, $repository);

        $barPath = SymbolPath::forClass('App', 'Bar');
        $barMetrics = $repository->get($barPath);

        self::assertSame(0, $barMetrics->get('ce_packages'));
    }

    // Framework CBO tests

    #[Test]
    public function calculate_cboAppExcludesFrameworkDeps(): void
    {
        // Configure framework namespaces
        $holder = new FrameworkNamespacesHolder();
        $holder->set(new FrameworkNamespaces(['Symfony', 'PhpParser', 'Psr']));
        $collector = new CouplingCollector($holder);

        // App\Service depends on Symfony\Console, PhpParser\Node, App\Repository
        $deps = [
            $this->dep('App\\Service', 'Symfony\\Component\\Console'),
            $this->dep('App\\Service', 'PhpParser\\Node'),
            $this->dep('App\\Service', 'App\\Repository'),
            $this->dep('App\\Controller', 'App\\Service'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Service');
        $this->registerClass($repository, 'App\\Repository');
        $this->registerClass($repository, 'App\\Controller');

        $collector->calculate($graph, $repository);

        $servicePath = SymbolPath::forClass('App', 'Service');
        $serviceMetrics = $repository->get($servicePath);

        // CBO = |{Symfony\Console, PhpParser\Node, App\Repository, App\Controller}| = 4
        self::assertSame(4, $serviceMetrics->get('cbo'));
        // CBO_APP = |{App\Repository, App\Controller}| = 2 (framework deps excluded)
        self::assertSame(2, $serviceMetrics->get('cbo_app'));
        // CE_FRAMEWORK = 2 (Symfony\Console, PhpParser\Node)
        self::assertSame(2, $serviceMetrics->get('ce_framework'));
    }

    #[Test]
    public function calculate_cboAppEqualsCboWhenNoFrameworkConfig(): void
    {
        // No framework namespaces configured (default)
        $deps = [
            $this->dep('App\\Service', 'Symfony\\Console'),
            $this->dep('App\\Service', 'App\\Repository'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Service');
        $this->registerClass($repository, 'App\\Repository');

        $this->collector->calculate($graph, $repository);

        $servicePath = SymbolPath::forClass('App', 'Service');
        $serviceMetrics = $repository->get($servicePath);

        // When no framework namespaces configured, CBO_APP = CBO
        self::assertSame($serviceMetrics->get('cbo'), $serviceMetrics->get('cbo_app'));
        self::assertSame(0, $serviceMetrics->get('ce_framework'));
    }

    #[Test]
    public function calculate_ceFrameworkCountsOnlyEfferentFrameworkDeps(): void
    {
        $holder = new FrameworkNamespacesHolder();
        $holder->set(new FrameworkNamespaces(['Symfony']));
        $collector = new CouplingCollector($holder);

        // App\Service depends on 3 Symfony classes
        $deps = [
            $this->dep('App\\Service', 'Symfony\\Console\\Command'),
            $this->dep('App\\Service', 'Symfony\\HttpFoundation\\Request'),
            $this->dep('App\\Service', 'Symfony\\DI\\Container'),
            $this->dep('App\\Service', 'App\\Repository'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Service');
        $this->registerClass($repository, 'App\\Repository');

        $collector->calculate($graph, $repository);

        $servicePath = SymbolPath::forClass('App', 'Service');
        $serviceMetrics = $repository->get($servicePath);

        self::assertSame(3, $serviceMetrics->get('ce_framework'));
    }

    #[Test]
    public function calculate_frameworkPrefixBoundaryAware(): void
    {
        $holder = new FrameworkNamespacesHolder();
        $holder->set(new FrameworkNamespaces(['Psr']));
        $collector = new CouplingCollector($holder);

        // Psr\Log should match, PsrExtended\Custom should NOT match
        $deps = [
            $this->dep('App\\Service', 'Psr\\Log\\LoggerInterface'),
            $this->dep('App\\Service', 'PsrExtended\\Custom\\Class_'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Service');

        $collector->calculate($graph, $repository);

        $servicePath = SymbolPath::forClass('App', 'Service');
        $serviceMetrics = $repository->get($servicePath);

        // CBO = 2 (both deps), CBO_APP = 1 (only PsrExtended), CE_FRAMEWORK = 1 (only Psr\Log)
        self::assertSame(2, $serviceMetrics->get('cbo'));
        self::assertSame(1, $serviceMetrics->get('cbo_app'));
        self::assertSame(1, $serviceMetrics->get('ce_framework'));
    }

    #[Test]
    public function calculate_cePartitionsCleanly(): void
    {
        // Verify: Ce = Ce_app + Ce_framework (outgoing dependencies partition cleanly)
        $holder = new FrameworkNamespacesHolder();
        $holder->set(new FrameworkNamespaces(['Symfony', 'PhpParser']));
        $collector = new CouplingCollector($holder);

        $deps = [
            $this->dep('App\\Service', 'Symfony\\Console'),
            $this->dep('App\\Service', 'PhpParser\\Node'),
            $this->dep('App\\Service', 'App\\Repository'),
            $this->dep('App\\Service', 'App\\Model'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Service');
        $this->registerClass($repository, 'App\\Repository');
        $this->registerClass($repository, 'App\\Model');

        $collector->calculate($graph, $repository);

        $servicePath = SymbolPath::forClass('App', 'Service');
        $serviceMetrics = $repository->get($servicePath);

        $ce = $serviceMetrics->get('ce');
        $ceFramework = $serviceMetrics->get('ce_framework');
        // Ce_app (non-framework efferent) = Ce - Ce_framework
        self::assertSame(4, $ce);
        self::assertSame(2, $ceFramework);
        // Note: CBO_APP also includes afferent app deps, so it's not just Ce - Ce_framework
    }

    #[Test]
    public function calculate_bidirectionalWithFramework(): void
    {
        // A→FrameworkClass — Ce_framework=1, but framework is not scanned
        // so Ca from framework doesn't exist. CBO_APP should exclude framework.
        $holder = new FrameworkNamespacesHolder();
        $holder->set(new FrameworkNamespaces(['Symfony']));
        $collector = new CouplingCollector($holder);

        $deps = [
            $this->dep('App\\Service', 'Symfony\\Console'),
            $this->dep('App\\Other', 'App\\Service'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Service');
        $this->registerClass($repository, 'App\\Other');

        $collector->calculate($graph, $repository);

        $servicePath = SymbolPath::forClass('App', 'Service');
        $serviceMetrics = $repository->get($servicePath);

        // CBO = |{Symfony\Console, App\Other}| = 2
        self::assertSame(2, $serviceMetrics->get('cbo'));
        // CBO_APP = |{App\Other}| = 1 (framework excluded)
        self::assertSame(1, $serviceMetrics->get('cbo_app'));
        self::assertSame(1, $serviceMetrics->get('ce_framework'));
    }

    private function dep(string $source, string $target): Dependency
    {
        return new Dependency(
            SymbolPath::fromClassFqn($source),
            SymbolPath::fromClassFqn($target),
            DependencyType::New_,
            new Location('/test.php', 1),
        );
    }

    private function registerClass(InMemoryMetricRepository $repository, string $fqn): void
    {
        $repository->add(SymbolPath::fromClassFqn($fqn), new MetricBag(), '/test.php', 1);
    }

    private function registerNamespace(InMemoryMetricRepository $repository, string $namespace): void
    {
        $repository->add(SymbolPath::forNamespace($namespace), new MetricBag(), '/test.php', null);
    }
}
