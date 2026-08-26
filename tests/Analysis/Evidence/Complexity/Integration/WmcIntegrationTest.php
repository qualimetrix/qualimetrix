<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Complexity\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\CyclomaticComplexityCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\AggregationHelper;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\MetricAggregator;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;

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
        // Setup repository with callable-level CCN metrics
        $repository = new InMemoryMetricRepository();

        $classPath = SymbolPath::forClass('App\Service', 'OrderProcessor');
        $method1Path = SymbolPath::forMethod('App\Service', 'OrderProcessor', 'process');
        $method2Path = SymbolPath::forMethod('App\Service', 'OrderProcessor', 'validate');
        $method3Path = SymbolPath::forMethod('App\Service', 'OrderProcessor', 'save');

        // Add method metrics: CCN values
        $this->addMethod($repository, $method1Path, $classPath, 5, 100);
        $this->addMethod($repository, $method2Path, $classPath, 3, 200);
        $this->addMethod($repository, $method3Path, $classPath, 2, 300);

        // Create aggregator with CCN collector
        $collector = new CyclomaticComplexityCollector();
        $aggregator = new MetricAggregator(AggregationHelper::collectDefinitions([$collector]), self::createStub(ProfilerInterface::class));

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
        $this->addMethod($repository, $method1Path, $classPath, 7, 100);
        $this->addMethod($repository, $method2Path, $classPath, 4, 200);

        // Aggregate
        $collector = new CyclomaticComplexityCollector();
        $aggregator = new MetricAggregator(AggregationHelper::collectDefinitions([$collector]), self::createStub(ProfilerInterface::class));
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
        $aggregator = new MetricAggregator(AggregationHelper::collectDefinitions([$collector]), self::createStub(ProfilerInterface::class));
        $aggregator->aggregate($repository);

        // Verify class has no WMC metric (since no methods)
        $classes = iterator_to_array($repository->all(SymbolLevel::Class_));
        self::assertCount(0, $classes, 'Class without methods should not be in repository');
    }

    #[Test]
    public function itComputesWmcForMultipleClasses(): void
    {
        // Setup repository with multiple classes
        $repository = new InMemoryMetricRepository();

        // Class 1
        $class1Path = SymbolPath::forClass('App', 'Class1');
        $repository->addCallable(new CallableWithMetrics(
            DeclarationPath::of(SymbolPath::forMethod('App', 'Class1', 'method1'), RelativePath::fromString('test1.php'), DeclarationOrdinal::fromRank(0)),
            100,
            CallableKind::Method,
            null,
            null,
            new LogicalClassPath($class1Path),
            (new MetricBag())->with('ccn', 10),
        ));

        // Class 2
        $class2Path = SymbolPath::forClass('App', 'Class2');
        $repository->addCallable(new CallableWithMetrics(
            DeclarationPath::of(SymbolPath::forMethod('App', 'Class2', 'methodA'), RelativePath::fromString('test2.php'), DeclarationOrdinal::fromRank(0)),
            100,
            CallableKind::Method,
            null,
            null,
            new LogicalClassPath($class2Path),
            (new MetricBag())->with('ccn', 15),
        ));
        $repository->addCallable(new CallableWithMetrics(
            DeclarationPath::of(SymbolPath::forMethod('App', 'Class2', 'methodB'), RelativePath::fromString('test2.php'), DeclarationOrdinal::fromRank(0)),
            200,
            CallableKind::Method,
            null,
            null,
            new LogicalClassPath($class2Path),
            (new MetricBag())->with('ccn', 5),
        ));

        // Aggregate
        $collector = new CyclomaticComplexityCollector();
        $aggregator = new MetricAggregator(AggregationHelper::collectDefinitions([$collector]), self::createStub(ProfilerInterface::class));
        $aggregator->aggregate($repository);

        // Verify both classes have WMC
        $class1Bag = $repository->get($class1Path);
        $class2Bag = $repository->get($class2Path);

        self::assertSame(10, (int) $class1Bag->get('wmc'));
        self::assertSame(20, (int) $class2Bag->get('wmc')); // 15 + 5 = 20
    }

    private function addMethod(InMemoryMetricRepository $repository, SymbolPath $method, SymbolPath $class, int $ccn, int $startFilePos): void
    {
        $repository->addCallable(new CallableWithMetrics(
            DeclarationPath::of($method, RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0)),
            $startFilePos,
            CallableKind::Method,
            null,
            null,
            new LogicalClassPath($class),
            (new MetricBag())->with('ccn', $ccn),
        ));
    }
}
