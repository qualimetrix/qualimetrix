<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Aggregator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Aggregator\AggregationHelper;
use Qualimetrix\Analysis\Aggregator\ClassToNamespaceAggregator;
use Qualimetrix\Analysis\Aggregator\MetricAggregator;
use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Metrics\Maintainability\MaintainabilityIndexCollector;
use Qualimetrix\Metrics\Size\LocCollector;

#[CoversClass(ClassToNamespaceAggregator::class)]
#[CoversClass(AggregationHelper::class)]
final class ClassToNamespaceAggregatorTest extends TestCase
{
    #[Test]
    public function itAggregatesProceduralFileLocToNamespace(): void
    {
        $repository = new InMemoryMetricRepository();

        // Add a global function in namespace App\Utils (no class in the file)
        $functionPath = SymbolPath::forGlobalFunction('App\\Utils', 'helper');
        $functionMetrics = (new MetricBag())->with('ccn', 2);
        $repository->add($functionPath, $functionMetrics, 'src/Utils/helpers.php', 10);

        // Add file-level LOC metrics for the same file
        $fileMetrics = (new MetricBag())
            ->with('loc', 50)
            ->with('lloc', 40)
            ->with('cloc', 5);
        $repository->add(
            SymbolPath::forFile('src/Utils/helpers.php'),
            $fileMetrics,
            'src/Utils/helpers.php',
            1,
        );

        $aggregator = new MetricAggregator(AggregationHelper::collectDefinitions([
            new LocCollector(),
        ]));
        $aggregator->aggregate($repository);

        // Namespace-level LOC should include the procedural file's LOC
        $nsMetrics = $repository->get(SymbolPath::forNamespace('App\\Utils'));

        self::assertSame(50, (int) $nsMetrics->get('loc.sum'));
        self::assertSame(40, (int) $nsMetrics->get('lloc.sum'));
        self::assertSame(5, (int) $nsMetrics->get('cloc.sum'));
    }

    #[Test]
    public function itAggregatesMixedClassAndFunctionFileLocToNamespace(): void
    {
        $repository = new InMemoryMetricRepository();

        // File with a class
        $repository->add(
            SymbolPath::forClass('App\\Service', 'UserService'),
            new MetricBag(),
            'src/Service/UserService.php',
            5,
        );
        $repository->add(
            SymbolPath::forFile('src/Service/UserService.php'),
            (new MetricBag())->with('loc', 100),
            'src/Service/UserService.php',
            1,
        );

        // File with only functions (no class)
        $repository->add(
            SymbolPath::forGlobalFunction('App\\Service', 'serviceHelper'),
            new MetricBag(),
            'src/Service/helpers.php',
            3,
        );
        $repository->add(
            SymbolPath::forFile('src/Service/helpers.php'),
            (new MetricBag())->with('loc', 30),
            'src/Service/helpers.php',
            1,
        );

        $aggregator = new MetricAggregator(AggregationHelper::collectDefinitions([
            new LocCollector(),
        ]));
        $aggregator->aggregate($repository);

        // Namespace LOC should include both files
        $nsMetrics = $repository->get(SymbolPath::forNamespace('App\\Service'));

        self::assertSame(130, (int) $nsMetrics->get('loc.sum')); // 100 + 30
    }

    #[Test]
    public function itAggregatesNonAdditiveMethodMetricsViaWeightedAverageFallback(): void
    {
        $repository = new InMemoryMetricRepository();

        // Class with 10 methods, MI avg=80 — weighs more than class with 2 methods
        $class1 = SymbolPath::forClass('App\\Service', 'UserService');
        $repository->add($class1, (new MetricBag())
            ->with('mi.avg', 80.0)
            ->with('mi.count', 10)
            ->with('mi.min', 70.0), 'src/Service/UserService.php', 10);

        $class2 = SymbolPath::forClass('App\\Service', 'OrderService');
        $repository->add($class2, (new MetricBag())
            ->with('mi.avg', 60.0)
            ->with('mi.count', 2)
            ->with('mi.min', 50.0), 'src/Service/OrderService.php', 10);

        $collector = new MaintainabilityIndexCollector();
        $aggregator = new MetricAggregator($collector->getMetricDefinitions());
        $aggregator->aggregate($repository);

        $nsMetrics = $repository->get(SymbolPath::forNamespace('App\\Service'));

        // Weighted average: (80*10 + 60*2) / (10+2) = 920/12 ≈ 76.67
        self::assertEqualsWithDelta(76.67, $nsMetrics->get('mi.avg'), 0.01);
        // mi.count at namespace = total method count = 12 (stored as int)
        self::assertSame(12, $nsMetrics->get('mi.count'));
        // Namespace mi.min = min of [80.0, 60.0] = 60.0
        self::assertEqualsWithDelta(60.0, $nsMetrics->get('mi.min'), 0.01);
    }

    #[Test]
    public function itFallsBackToPlainAverageWhenCountMissing(): void
    {
        $repository = new InMemoryMetricRepository();

        // Legacy data without .count — should fall back to plain average (weight=1.0)
        $class1 = SymbolPath::forClass('App\\Service', 'UserService');
        $repository->add($class1, (new MetricBag())
            ->with('mi.avg', 80.0)
            ->with('mi.min', 70.0), 'src/Service/UserService.php', 10);

        $class2 = SymbolPath::forClass('App\\Service', 'OrderService');
        $repository->add($class2, (new MetricBag())
            ->with('mi.avg', 60.0)
            ->with('mi.min', 50.0), 'src/Service/OrderService.php', 10);

        $collector = new MaintainabilityIndexCollector();
        $aggregator = new MetricAggregator($collector->getMetricDefinitions());
        $aggregator->aggregate($repository);

        $nsMetrics = $repository->get(SymbolPath::forNamespace('App\\Service'));

        // Without .count, weight=1.0 each → plain average: (80+60)/2 = 70
        self::assertEqualsWithDelta(70.0, $nsMetrics->get('mi.avg'), 0.01);
        // mi.count = 2 (fallback weight=1.0 per class, sum of weights = 2)
        self::assertSame(2, $nsMetrics->get('mi.count'));
    }

    #[Test]
    public function itHandlesSingleClassNamespaceCorrectly(): void
    {
        $repository = new InMemoryMetricRepository();

        // Single class with 5 methods — weighted average should equal the class avg
        $class1 = SymbolPath::forClass('App\\Single', 'OnlyService');
        $repository->add($class1, (new MetricBag())
            ->with('mi.avg', 85.0)
            ->with('mi.count', 5)
            ->with('mi.min', 75.0), 'src/Single/OnlyService.php', 10);

        $collector = new MaintainabilityIndexCollector();
        $aggregator = new MetricAggregator($collector->getMetricDefinitions());
        $aggregator->aggregate($repository);

        $nsMetrics = $repository->get(SymbolPath::forNamespace('App\\Single'));

        // Single class: weighted avg = 85.0 (trivially)
        self::assertEqualsWithDelta(85.0, $nsMetrics->get('mi.avg'), 0.01);
        self::assertSame(5, $nsMetrics->get('mi.count'));
        // min is computed from collected values (.avg fallback) = min([85.0]) = 85.0
        self::assertEqualsWithDelta(85.0, $nsMetrics->get('mi.min'), 0.01);
    }

    #[Test]
    public function itPrefersSumOverAverageForAdditiveMethodMetrics(): void
    {
        $repository = new InMemoryMetricRepository();

        // Two classes with both Sum and Average at class level — Sum should be preferred
        $class1 = SymbolPath::forClass('App\\Service', 'UserService');
        $repository->add($class1, (new MetricBag())
            ->with('ccn.sum', 20.0)
            ->with('ccn.avg', 5.0), 'src/Service/UserService.php', 10);

        $class2 = SymbolPath::forClass('App\\Service', 'OrderService');
        $repository->add($class2, (new MetricBag())
            ->with('ccn.sum', 30.0)
            ->with('ccn.avg', 10.0), 'src/Service/OrderService.php', 10);

        $definition = new MetricDefinition(
            name: 'ccn',
            collectedAt: SymbolLevel::Method,
            aggregations: [
                SymbolLevel::Class_->value => [AggregationStrategy::Sum, AggregationStrategy::Average],
                SymbolLevel::Namespace_->value => [AggregationStrategy::Sum, AggregationStrategy::Average],
            ],
        );
        $aggregator = new MetricAggregator([$definition]);
        $aggregator->aggregate($repository);

        $nsMetrics = $repository->get(SymbolPath::forNamespace('App\\Service'));

        // Sum is preferred: namespace ccn.sum = sum of class sums = 50
        self::assertEqualsWithDelta(50.0, $nsMetrics->get('ccn.sum'), 0.01);
        // namespace ccn.avg = average of class sums = 25 (not average of class averages)
        self::assertEqualsWithDelta(25.0, $nsMetrics->get('ccn.avg'), 0.01);
    }

    #[Test]
    public function itAdditiveMetricsAreUnaffectedByWeights(): void
    {
        $repository = new InMemoryMetricRepository();

        // Additive metrics (CCN with Sum) use weight=1.0 — Sum/Max/Min are unaffected
        $class1 = SymbolPath::forClass('App\\Service', 'UserService');
        $repository->add($class1, (new MetricBag())
            ->with('ccn.sum', 20.0)
            ->with('ccn.avg', 5.0)
            ->with('ccn.count', 4), 'src/Service/UserService.php', 10);

        $class2 = SymbolPath::forClass('App\\Service', 'OrderService');
        $repository->add($class2, (new MetricBag())
            ->with('ccn.sum', 30.0)
            ->with('ccn.avg', 10.0)
            ->with('ccn.count', 3), 'src/Service/OrderService.php', 10);

        $definition = new MetricDefinition(
            name: 'ccn',
            collectedAt: SymbolLevel::Method,
            aggregations: [
                SymbolLevel::Class_->value => [AggregationStrategy::Sum, AggregationStrategy::Average],
                SymbolLevel::Namespace_->value => [AggregationStrategy::Sum, AggregationStrategy::Average],
            ],
        );
        $aggregator = new MetricAggregator([$definition]);
        $aggregator->aggregate($repository);

        $nsMetrics = $repository->get(SymbolPath::forNamespace('App\\Service'));

        // Sum uses .sum values (not .avg), weight=1.0 → Sum unaffected
        self::assertEqualsWithDelta(50.0, $nsMetrics->get('ccn.sum'), 0.01);
        // Average of .sum values with weight=1.0 → plain average: (20+30)/2 = 25
        self::assertEqualsWithDelta(25.0, $nsMetrics->get('ccn.avg'), 0.01);
    }

    #[Test]
    public function applyStrategyWithWeightsComputesWeightedAverage(): void
    {
        // Direct unit test for AggregationHelper::applyStrategy with weights
        $values = [80.0, 60.0];
        $weights = [10.0, 2.0];

        $result = AggregationHelper::applyStrategy(AggregationStrategy::Average, $values, $weights);

        // (80*10 + 60*2) / 12 = 76.666...
        self::assertEqualsWithDelta(76.67, $result, 0.01);

        // Non-Average strategies ignore weights
        $sum = AggregationHelper::applyStrategy(AggregationStrategy::Sum, $values, $weights);
        self::assertEqualsWithDelta(140.0, $sum, 0.01);

        $max = AggregationHelper::applyStrategy(AggregationStrategy::Max, $values, $weights);
        self::assertEqualsWithDelta(80.0, $max, 0.01);
    }

    #[Test]
    public function applyAggregationsAutoStoresCountAlongsideAverage(): void
    {
        $definition = new MetricDefinition(
            name: 'mi',
            collectedAt: SymbolLevel::Method,
            aggregations: [
                SymbolLevel::Namespace_->value => [AggregationStrategy::Average, AggregationStrategy::Min],
            ],
        );

        $metricValues = ['mi' => [80.0, 60.0, 70.0]];

        $bag = AggregationHelper::applyAggregations($metricValues, [$definition], SymbolLevel::Namespace_);

        // avg stored
        self::assertEqualsWithDelta(70.0, $bag->get('mi.avg'), 0.01);
        // count auto-stored = 3
        self::assertEqualsWithDelta(3.0, $bag->get('mi.count'), 0.01);
        // min stored
        self::assertEqualsWithDelta(60.0, $bag->get('mi.min'), 0.01);
    }

    #[Test]
    public function applyAggregationsDoesNotDuplicateExplicitCount(): void
    {
        $definition = new MetricDefinition(
            name: 'test',
            collectedAt: SymbolLevel::Method,
            aggregations: [
                SymbolLevel::Namespace_->value => [
                    AggregationStrategy::Average,
                    AggregationStrategy::Count,
                ],
            ],
        );

        $metricValues = ['test' => [10.0, 20.0]];

        $bag = AggregationHelper::applyAggregations($metricValues, [$definition], SymbolLevel::Namespace_);

        // Explicit Count strategy: count = 2
        self::assertEqualsWithDelta(2.0, $bag->get('test.count'), 0.01);
        self::assertEqualsWithDelta(15.0, $bag->get('test.avg'), 0.01);
    }
}
