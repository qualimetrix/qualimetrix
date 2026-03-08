<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Coupling;

use AiMessDetector\Analysis\Collection\Dependency\DependencyGraphBuilder;
use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Dependency\DependencyType;
use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\SymbolPath;
use AiMessDetector\Metrics\Coupling\CouplingCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CouplingCollector::class)]
final class CouplingCollectorTest extends TestCase
{
    private CouplingCollector $collector;
    private DependencyGraphBuilder $graphBuilder;

    protected function setUp(): void
    {
        $this->collector = new CouplingCollector();
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
        self::assertSame(['ca', 'ce', 'cbo', 'instability'], $this->collector->provides());
    }

    #[Test]
    public function getMetricDefinitions_returnsFourDefinitions(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(4, $definitions);

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
            [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max],
            $cbo->getStrategiesForLevel(SymbolLevel::Namespace_),
        );
        self::assertSame(
            [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max],
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
    }

    #[Test]
    public function calculate_computesCbo(): void
    {
        // App\Service has Ca = 1 (App\Controller depends on it)
        // App\Service has Ce = 2 (depends on Vendor\A and Vendor\B)
        // CBO = Ca + Ce = 1 + 2 = 3
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
        // CBO = 3 + 4 = 7
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
        self::assertSame(2, $appNsMetrics->get('cbo'));
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
