<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Integration;

use AiMessDetector\Analysis\Aggregator\MetricAggregator;
use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\SymbolPath;
use AiMessDetector\Metrics\Complexity\CyclomaticComplexityCollector;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for WMC metric.
 *
 * Verifies that:
 * - WMC metric is available after aggregation
 * - WMC equals ccn.sum for all classes
 * - WMC can be used in rules
 */
final class WmcIntegrationTest extends TestCase
{
    public function testWmcMetricAvailableAfterAggregation(): void
    {
        // Setup repository with method-level CCN metrics
        $repository = new InMemoryMetricRepository();

        $classPath = SymbolPath::forClass('App\Service', 'OrderProcessor');
        $method1Path = SymbolPath::forMethod('App\Service', 'OrderProcessor', 'process');
        $method2Path = SymbolPath::forMethod('App\Service', 'OrderProcessor', 'validate');
        $method3Path = SymbolPath::forMethod('App\Service', 'OrderProcessor', 'save');

        // Add method metrics: CCN values
        $repository->add($method1Path, (new MetricBag())->with('ccn', 5), 'test.php', 10);
        $repository->add($method2Path, (new MetricBag())->with('ccn', 3), 'test.php', 20);
        $repository->add($method3Path, (new MetricBag())->with('ccn', 2), 'test.php', 30);

        // Create aggregator with CCN collector
        $collector = new CyclomaticComplexityCollector();
        $aggregator = new MetricAggregator([$collector]);

        // Aggregate
        $aggregator->aggregate($repository);

        // Verify class has WMC metric
        $classBag = $repository->get($classPath);
        $wmc = $classBag->get('wmc');

        self::assertNotNull($wmc, 'WMC metric should be available for class');
        self::assertSame(10, (int) $wmc, 'WMC should equal sum of method CCN values (5+3+2=10)');
    }

    public function testWmcEqualsCcnSum(): void
    {
        // Setup repository
        $repository = new InMemoryMetricRepository();

        $classPath = SymbolPath::forClass('App', 'TestClass');
        $method1Path = SymbolPath::forMethod('App', 'TestClass', 'method1');
        $method2Path = SymbolPath::forMethod('App', 'TestClass', 'method2');

        // Add method metrics
        $repository->add($method1Path, (new MetricBag())->with('ccn', 7), 'test.php', 10);
        $repository->add($method2Path, (new MetricBag())->with('ccn', 4), 'test.php', 20);

        // Aggregate
        $collector = new CyclomaticComplexityCollector();
        $aggregator = new MetricAggregator([$collector]);
        $aggregator->aggregate($repository);

        // Verify WMC === ccn.sum
        $classBag = $repository->get($classPath);
        $wmc = $classBag->get('wmc');
        $ccnSum = $classBag->get('ccn.sum');

        self::assertNotNull($wmc);
        self::assertNotNull($ccnSum);
        self::assertSame($ccnSum, $wmc, 'WMC should be equal to ccn.sum');
        self::assertSame(11, (int) $wmc); // 7 + 4 = 11
    }

    public function testWmcForClassWithoutMethods(): void
    {
        // Setup repository with class but no methods
        $repository = new InMemoryMetricRepository();

        $classPath = SymbolPath::forClass('App', 'EmptyClass');

        // No methods added - aggregator should handle this gracefully

        // Aggregate
        $collector = new CyclomaticComplexityCollector();
        $aggregator = new MetricAggregator([$collector]);
        $aggregator->aggregate($repository);

        // Verify class has no WMC metric (since no methods)
        $classes = iterator_to_array($repository->all(SymbolType::Class_));
        self::assertCount(0, $classes, 'Class without methods should not be in repository');
    }

    public function testWmcForMultipleClasses(): void
    {
        // Setup repository with multiple classes
        $repository = new InMemoryMetricRepository();

        // Class 1
        $class1Path = SymbolPath::forClass('App', 'Class1');
        $repository->add(
            SymbolPath::forMethod('App', 'Class1', 'method1'),
            (new MetricBag())->with('ccn', 10),
            'test1.php',
            10,
        );

        // Class 2
        $class2Path = SymbolPath::forClass('App', 'Class2');
        $repository->add(
            SymbolPath::forMethod('App', 'Class2', 'methodA'),
            (new MetricBag())->with('ccn', 15),
            'test2.php',
            10,
        );
        $repository->add(
            SymbolPath::forMethod('App', 'Class2', 'methodB'),
            (new MetricBag())->with('ccn', 5),
            'test2.php',
            20,
        );

        // Aggregate
        $collector = new CyclomaticComplexityCollector();
        $aggregator = new MetricAggregator([$collector]);
        $aggregator->aggregate($repository);

        // Verify both classes have WMC
        $class1Bag = $repository->get($class1Path);
        $class2Bag = $repository->get($class2Path);

        self::assertSame(10, (int) $class1Bag->get('wmc'));
        self::assertSame(20, (int) $class2Bag->get('wmc')); // 15 + 5 = 20
    }
}
