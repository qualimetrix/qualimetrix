<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Aggregator\AggregationHelper;
use Qualimetrix\Analysis\Aggregator\MetricAggregator;
use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Metrics\Complexity\CyclomaticComplexityCollector;

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
    #[Test]
    public function itMakesWmcMetricAvailableAfterAggregation(): void
    {
        // Setup repository with method-level CCN metrics
        $repository = new InMemoryMetricRepository();

        $classPath = SymbolPath::forClass('App\Service', 'OrderProcessor');
        $method1Path = SymbolPath::forMethod('App\Service', 'OrderProcessor', 'process');
        $method2Path = SymbolPath::forMethod('App\Service', 'OrderProcessor', 'validate');
        $method3Path = SymbolPath::forMethod('App\Service', 'OrderProcessor', 'save');

        // Add method metrics: CCN values
        $repository->add($method1Path, (new MetricBag())->with('ccn', 5), RelativePath::fromString('test.php'), 10);
        $repository->add($method2Path, (new MetricBag())->with('ccn', 3), RelativePath::fromString('test.php'), 20);
        $repository->add($method3Path, (new MetricBag())->with('ccn', 2), RelativePath::fromString('test.php'), 30);

        // Create aggregator with CCN collector
        $collector = new CyclomaticComplexityCollector();
        $aggregator = new MetricAggregator(AggregationHelper::collectDefinitions([$collector]));

        // Aggregate
        $aggregator->aggregate($repository);

        // Verify class has WMC metric
        $classBag = $repository->get($classPath);
        $wmc = $classBag->get('wmc');

        self::assertNotNull($wmc, 'WMC metric should be available for class');
        self::assertSame(10, (int) $wmc, 'WMC should equal sum of method CCN values (5+3+2=10)');
    }

    #[Test]
    public function itVerifiesWmcEqualsCcnSum(): void
    {
        // Setup repository
        $repository = new InMemoryMetricRepository();

        $classPath = SymbolPath::forClass('App', 'TestClass');
        $method1Path = SymbolPath::forMethod('App', 'TestClass', 'method1');
        $method2Path = SymbolPath::forMethod('App', 'TestClass', 'method2');

        // Add method metrics
        $repository->add($method1Path, (new MetricBag())->with('ccn', 7), RelativePath::fromString('test.php'), 10);
        $repository->add($method2Path, (new MetricBag())->with('ccn', 4), RelativePath::fromString('test.php'), 20);

        // Aggregate
        $collector = new CyclomaticComplexityCollector();
        $aggregator = new MetricAggregator(AggregationHelper::collectDefinitions([$collector]));
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

    #[Test]
    public function itHandlesClassWithoutMethodsGracefully(): void
    {
        // Setup repository with class but no methods
        $repository = new InMemoryMetricRepository();

        $classPath = SymbolPath::forClass('App', 'EmptyClass');

        // No methods added - aggregator should handle this gracefully

        // Aggregate
        $collector = new CyclomaticComplexityCollector();
        $aggregator = new MetricAggregator(AggregationHelper::collectDefinitions([$collector]));
        $aggregator->aggregate($repository);

        // Verify class has no WMC metric (since no methods)
        $classes = iterator_to_array($repository->all(SymbolType::Class_));
        self::assertCount(0, $classes, 'Class without methods should not be in repository');
    }

    #[Test]
    public function itComputesWmcForMultipleClasses(): void
    {
        // Setup repository with multiple classes
        $repository = new InMemoryMetricRepository();

        // Class 1
        $class1Path = SymbolPath::forClass('App', 'Class1');
        $repository->add(
            SymbolPath::forMethod('App', 'Class1', 'method1'),
            (new MetricBag())->with('ccn', 10),
            RelativePath::fromString('test1.php'),
            10,
        );

        // Class 2
        $class2Path = SymbolPath::forClass('App', 'Class2');
        $repository->add(
            SymbolPath::forMethod('App', 'Class2', 'methodA'),
            (new MetricBag())->with('ccn', 15),
            RelativePath::fromString('test2.php'),
            10,
        );
        $repository->add(
            SymbolPath::forMethod('App', 'Class2', 'methodB'),
            (new MetricBag())->with('ccn', 5),
            RelativePath::fromString('test2.php'),
            20,
        );

        // Aggregate
        $collector = new CyclomaticComplexityCollector();
        $aggregator = new MetricAggregator(AggregationHelper::collectDefinitions([$collector]));
        $aggregator->aggregate($repository);

        // Verify both classes have WMC
        $class1Bag = $repository->get($class1Path);
        $class2Bag = $repository->get($class2Path);

        self::assertSame(10, (int) $class1Bag->get('wmc'));
        self::assertSame(20, (int) $class2Bag->get('wmc')); // 15 + 5 = 20
    }
}
