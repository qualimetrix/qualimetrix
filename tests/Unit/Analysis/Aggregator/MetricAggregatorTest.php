<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Aggregator;

use AiMessDetector\Analysis\Aggregator\AggregationHelper;
use AiMessDetector\Analysis\Aggregator\MetricAggregator;
use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricCollectorInterface;
use AiMessDetector\Core\Violation\SymbolPath;
use AiMessDetector\Metrics\Complexity\CyclomaticComplexityCollector;
use AiMessDetector\Metrics\Size\ClassCountCollector;
use AiMessDetector\Metrics\Size\LocCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetricAggregator::class)]
final class MetricAggregatorTest extends TestCase
{
    #[Test]
    public function itAggregatesMetricsByNamespace(): void
    {
        $repository = new InMemoryMetricRepository();

        // Add methods with CCN
        $method1Metrics = (new MetricBag())->with('ccn', 5);
        $repository->add(
            SymbolPath::forMethod('App\\Service', 'UserService', 'find'),
            $method1Metrics,
            'src/Service/UserService.php',
            10,
        );

        $method2Metrics = (new MetricBag())->with('ccn', 3);
        $repository->add(
            SymbolPath::forMethod('App\\Service', 'UserService', 'save'),
            $method2Metrics,
            'src/Service/UserService.php',
            30,
        );

        $aggregator = $this->createAggregator();
        $aggregator->aggregate($repository);

        // Check class-level aggregation
        $classMetrics = $repository->get(SymbolPath::forClass('App\\Service', 'UserService'));
        self::assertSame(8, (int) $classMetrics->get('ccn.sum')); // 5 + 3
        self::assertEquals(4.0, $classMetrics->get('ccn.avg')); // (5 + 3) / 2 - use assertEquals for int/float comparison
        self::assertSame(5, (int) $classMetrics->get('ccn.max'));
        self::assertSame(2, $classMetrics->get('symbolMethodCount')); // 2 methods in class

        // Check namespace-level aggregation
        $nsMetrics = $repository->get(SymbolPath::forNamespace('App\\Service'));
        self::assertInstanceOf(MetricBag::class, $nsMetrics);
        self::assertSame(2, $nsMetrics->get('symbolMethodCount'));
        self::assertSame(8, (int) $nsMetrics->get('ccn.sum')); // Sum of class ccn.sum
        self::assertEquals(8.0, $nsMetrics->get('ccn.avg')); // Avg of class ccn.sum (only one class with sum 8)
        self::assertSame(8, (int) $nsMetrics->get('ccn.max'));
    }

    #[Test]
    public function itAggregatesMethodCountAtClassLevel(): void
    {
        $repository = new InMemoryMetricRepository();

        // Class with 3 methods
        $method1 = (new MetricBag())->with('ccn', 2);
        $repository->add(
            SymbolPath::forMethod('App\\Service', 'OrderService', 'create'),
            $method1,
            'src/Service/OrderService.php',
            10,
        );

        $method2 = (new MetricBag())->with('ccn', 5);
        $repository->add(
            SymbolPath::forMethod('App\\Service', 'OrderService', 'update'),
            $method2,
            'src/Service/OrderService.php',
            30,
        );

        $method3 = (new MetricBag())->with('ccn', 8);
        $repository->add(
            SymbolPath::forMethod('App\\Service', 'OrderService', 'delete'),
            $method3,
            'src/Service/OrderService.php',
            50,
        );

        $aggregator = $this->createAggregator();
        $aggregator->aggregate($repository);

        // Check class-level metrics
        $classMetrics = $repository->get(SymbolPath::forClass('App\\Service', 'OrderService'));

        // Method count
        self::assertSame(3, $classMetrics->get('symbolMethodCount'));

        // CCN aggregations
        self::assertSame(15, (int) $classMetrics->get('ccn.sum')); // 2 + 5 + 8
        self::assertEquals(5.0, $classMetrics->get('ccn.avg')); // (2 + 5 + 8) / 3
        self::assertSame(8, (int) $classMetrics->get('ccn.max')); // max is 8
    }

    #[Test]
    public function itAggregatesProjectLevelMetrics(): void
    {
        $repository = new InMemoryMetricRepository();

        // Namespace 1
        $method1 = (new MetricBag())->with('ccn', 4);
        $repository->add(
            SymbolPath::forMethod('App\\Service', 'ServiceA', 'execute'),
            $method1,
            'src/Service/ServiceA.php',
            10,
        );

        // Namespace 2
        $method2 = (new MetricBag())->with('ccn', 6);
        $repository->add(
            SymbolPath::forMethod('App\\Repository', 'RepoA', 'find'),
            $method2,
            'src/Repository/RepoA.php',
            10,
        );

        $aggregator = $this->createAggregator();
        $aggregator->aggregate($repository);

        // Project level (empty namespace)
        $projectMetrics = $repository->get(SymbolPath::forProject());

        self::assertInstanceOf(MetricBag::class, $projectMetrics);
        self::assertSame(2, $projectMetrics->get('symbolMethodCount'));
        self::assertSame(10, (int) $projectMetrics->get('ccn.sum')); // 4 + 6
        self::assertSame(6, (int) $projectMetrics->get('ccn.max'));
    }

    #[Test]
    public function itHandlesEmptyRepository(): void
    {
        $repository = new InMemoryMetricRepository();

        $aggregator = $this->createAggregator();
        $aggregator->aggregate($repository);

        // No namespaces, so no project metrics
        $projectMetrics = $repository->get(SymbolPath::forProject());

        // Empty MetricBag is returned for non-existent symbols
        self::assertInstanceOf(MetricBag::class, $projectMetrics);
        self::assertSame([], $projectMetrics->all());
    }

    #[Test]
    public function itHandlesNamespaceWithoutMethods(): void
    {
        $repository = new InMemoryMetricRepository();

        // Add file-level metrics only (no methods)
        $fileMetrics = (new MetricBag())
            ->with('loc', 50)
            ->with('classCount', 1);
        $repository->add(
            SymbolPath::forFile('src/Entity/User.php'),
            $fileMetrics,
            'src/Entity/User.php',
            1,
        );

        // Add a class placeholder to register namespace
        $classMetrics = new MetricBag();
        $repository->add(
            SymbolPath::forClass('App\\Entity', 'User'),
            $classMetrics,
            'src/Entity/User.php',
            1,
        );

        $aggregator = $this->createAggregator();
        $aggregator->aggregate($repository);

        $nsMetrics = $repository->get(SymbolPath::forNamespace('App\\Entity'));

        self::assertInstanceOf(MetricBag::class, $nsMetrics);
        self::assertSame(0, $nsMetrics->get('symbolMethodCount'));
        // CCN aggregation won't have values since there are no methods
        self::assertNull($nsMetrics->get('ccn.sum'));
    }

    #[Test]
    public function itAggregatesLocMetrics(): void
    {
        $repository = new InMemoryMetricRepository();

        // Add file with LOC metrics
        $file1Metrics = (new MetricBag())
            ->with('loc', 100)
            ->with('lloc', 80)
            ->with('cloc', 10);
        $repository->add(
            SymbolPath::forFile('src/Service/ServiceA.php'),
            $file1Metrics,
            'src/Service/ServiceA.php',
            1,
        );

        // Add class to register namespace
        $classMetrics = new MetricBag();
        $repository->add(
            SymbolPath::forClass('App\\Service', 'ServiceA'),
            $classMetrics,
            'src/Service/ServiceA.php',
            1,
        );

        $aggregator = $this->createAggregator();
        $aggregator->aggregate($repository);

        $nsMetrics = $repository->get(SymbolPath::forNamespace('App\\Service'));

        // LOC metrics should be aggregated
        self::assertSame(100, (int) $nsMetrics->get('loc.sum'));
        self::assertSame(80, (int) $nsMetrics->get('lloc.sum'));
        self::assertSame(10, (int) $nsMetrics->get('cloc.sum'));
    }

    #[Test]
    public function itAggregatesClassCountMetrics(): void
    {
        $repository = new InMemoryMetricRepository();

        // Add files with class counts
        $file1Metrics = (new MetricBag())
            ->with('classCount', 2)
            ->with('interfaceCount', 1);
        $repository->add(
            SymbolPath::forFile('src/Service/Services.php'),
            $file1Metrics,
            'src/Service/Services.php',
            1,
        );

        // Add class to register namespace
        $classMetrics = new MetricBag();
        $repository->add(
            SymbolPath::forClass('App\\Service', 'ServiceA'),
            $classMetrics,
            'src/Service/Services.php',
            1,
        );

        $aggregator = $this->createAggregator();
        $aggregator->aggregate($repository);

        $nsMetrics = $repository->get(SymbolPath::forNamespace('App\\Service'));

        self::assertSame(2, (int) $nsMetrics->get('classCount.sum'));
        self::assertSame(1, (int) $nsMetrics->get('interfaceCount.sum'));
    }

    #[Test]
    public function itHandlesCollectorsWithNoDefinitions(): void
    {
        // Create a collector that returns no metric definitions
        $collectorWithoutDefinitions = $this->createMock(MetricCollectorInterface::class);
        $collectorWithoutDefinitions->method('getMetricDefinitions')->willReturn([]);
        $collectorWithoutDefinitions->method('getName')->willReturn('empty');

        $repository = new InMemoryMetricRepository();

        // Add some data
        $method1 = (new MetricBag())->with('test', 1);
        $repository->add(
            SymbolPath::forMethod('App', 'Service', 'method'),
            $method1,
            'test.php',
            10,
        );

        $aggregator = new MetricAggregator(AggregationHelper::collectDefinitions([$collectorWithoutDefinitions]));
        $aggregator->aggregate($repository);

        // Should not crash, no class-level metrics should be added
        $classMetrics = $repository->get(SymbolPath::forClass('App', 'Service'));
        self::assertSame([], $classMetrics->all());
    }

    #[Test]
    public function itSkipsAggregationForClassesWithoutMethods(): void
    {
        $repository = new InMemoryMetricRepository();

        // Add only class without methods
        $classMetrics = new MetricBag();
        $repository->add(
            SymbolPath::forClass('App\\Service', 'EmptyClass'),
            $classMetrics,
            'src/Service/EmptyClass.php',
            1,
        );

        $aggregator = $this->createAggregator();
        $aggregator->aggregate($repository);

        // Should not have aggregated metrics since there are no methods
        $classResult = $repository->get(SymbolPath::forClass('App\\Service', 'EmptyClass'));
        // Original class metrics should be unchanged (no ccn.sum, etc.)
        self::assertNull($classResult->get('ccn.sum'));
    }

    #[Test]
    public function itHandlesEmptyNamespace(): void
    {
        $repository = new InMemoryMetricRepository();

        // Add class in global namespace (empty namespace)
        $method = (new MetricBag())->with('ccn', 5);
        $repository->add(
            SymbolPath::forMethod('', 'GlobalClass', 'method'),
            $method,
            'global.php',
            10,
        );

        $aggregator = $this->createAggregator();
        $aggregator->aggregate($repository);

        // Should aggregate to class in empty namespace
        $classMetrics = $repository->get(SymbolPath::forClass('', 'GlobalClass'));
        self::assertSame(5, (int) $classMetrics->get('ccn.sum'));
    }

    #[Test]
    public function itAcceptsIterableCollectors(): void
    {
        // Test with definitions from multiple collectors
        $collectors = [
            new CyclomaticComplexityCollector(),
            new LocCollector(),
        ];

        $aggregator = new MetricAggregator(AggregationHelper::collectDefinitions($collectors));
        $repository = new InMemoryMetricRepository();

        // Add test data
        $method = (new MetricBag())->with('ccn', 3);
        $repository->add(
            SymbolPath::forMethod('App', 'Test', 'method'),
            $method,
            'test.php',
            10,
        );

        $aggregator->aggregate($repository);

        // Should work same as with array
        $classMetrics = $repository->get(SymbolPath::forClass('App', 'Test'));
        self::assertSame(3, (int) $classMetrics->get('ccn.sum'));
    }

    #[Test]
    public function itHandlesMultipleNamespaces(): void
    {
        $repository = new InMemoryMetricRepository();

        // Namespace 1 with 2 classes
        $method1 = (new MetricBag())->with('ccn', 5);
        $repository->add(
            SymbolPath::forMethod('App\\Service', 'ServiceA', 'execute'),
            $method1,
            'src/Service/ServiceA.php',
            10,
        );

        $method2 = (new MetricBag())->with('ccn', 3);
        $repository->add(
            SymbolPath::forMethod('App\\Service', 'ServiceB', 'run'),
            $method2,
            'src/Service/ServiceB.php',
            10,
        );

        // Namespace 2 with 1 class
        $method3 = (new MetricBag())->with('ccn', 8);
        $repository->add(
            SymbolPath::forMethod('App\\Repository', 'UserRepo', 'find'),
            $method3,
            'src/Repository/UserRepo.php',
            10,
        );

        $aggregator = $this->createAggregator();
        $aggregator->aggregate($repository);

        // Check namespace 1
        $ns1Metrics = $repository->get(SymbolPath::forNamespace('App\\Service'));
        self::assertSame(2, $ns1Metrics->get('symbolClassCount'));
        self::assertSame(8, (int) $ns1Metrics->get('ccn.sum')); // 5 + 3

        // Check namespace 2
        $ns2Metrics = $repository->get(SymbolPath::forNamespace('App\\Repository'));
        self::assertSame(1, $ns2Metrics->get('symbolClassCount'));
        self::assertSame(8, (int) $ns2Metrics->get('ccn.sum'));

        // Check project level
        $projectMetrics = $repository->get(SymbolPath::forProject());
        self::assertSame(3, $projectMetrics->get('symbolClassCount'));
        self::assertSame(16, (int) $projectMetrics->get('ccn.sum')); // 8 + 8
    }

    private function createAggregator(): MetricAggregator
    {
        return new MetricAggregator(AggregationHelper::collectDefinitions([
            new CyclomaticComplexityCollector(),
            new ClassCountCollector(),
            new LocCollector(),
        ]));
    }
}
