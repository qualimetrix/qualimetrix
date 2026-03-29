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
    public function itAggregatesNonAdditiveMethodMetricsFromRawMethodValues(): void
    {
        $repository = new InMemoryMetricRepository();

        // Class with 10 methods, MI avg=80
        $class1 = SymbolPath::forClass('App\\Service', 'UserService');
        $repository->add($class1, (new MetricBag())
            ->with('mi.avg', 80.0)
            ->with('mi.count', 10)
            ->with('mi.min', 70.0), 'src/Service/UserService.php', 10);

        // Add 10 method symbols with mi=80 each
        for ($i = 1; $i <= 10; $i++) {
            $this->addMethod($repository, 'App\\Service', 'UserService', "method{$i}", 'src/Service/UserService.php', 'mi', 80.0);
        }

        // Class with 2 methods, MI avg=60
        $class2 = SymbolPath::forClass('App\\Service', 'OrderService');
        $repository->add($class2, (new MetricBag())
            ->with('mi.avg', 60.0)
            ->with('mi.count', 2)
            ->with('mi.min', 50.0), 'src/Service/OrderService.php', 10);

        // Add 2 method symbols with mi=60 each
        $this->addMethod($repository, 'App\\Service', 'OrderService', 'method1', 'src/Service/OrderService.php', 'mi', 60.0);
        $this->addMethod($repository, 'App\\Service', 'OrderService', 'method2', 'src/Service/OrderService.php', 'mi', 60.0);

        $collector = new MaintainabilityIndexCollector();
        $aggregator = new MetricAggregator($collector->getMetricDefinitions());
        $aggregator->aggregate($repository);

        $nsMetrics = $repository->get(SymbolPath::forNamespace('App\\Service'));

        // Average from raw method values: (80*10 + 60*2) / 12 = 920/12 ≈ 76.67
        self::assertEqualsWithDelta(76.67, $nsMetrics->get('mi.avg'), 0.01);
        // mi.count = total method count = 12
        self::assertSame(12, $nsMetrics->get('mi.count'));
        // mi.min = min of all method values = 60.0
        self::assertEqualsWithDelta(60.0, $nsMetrics->get('mi.min'), 0.01);
    }

    #[Test]
    public function itAggregatesMethodMetricsEvenWhenClassLevelCountMissing(): void
    {
        $repository = new InMemoryMetricRepository();

        // Class-level data without .count — but method symbols exist
        $class1 = SymbolPath::forClass('App\\Service', 'UserService');
        $repository->add($class1, (new MetricBag())
            ->with('mi.avg', 80.0)
            ->with('mi.min', 70.0), 'src/Service/UserService.php', 10);

        // 1 method with mi=80
        $this->addMethod($repository, 'App\\Service', 'UserService', 'handle', 'src/Service/UserService.php', 'mi', 80.0);

        $class2 = SymbolPath::forClass('App\\Service', 'OrderService');
        $repository->add($class2, (new MetricBag())
            ->with('mi.avg', 60.0)
            ->with('mi.min', 50.0), 'src/Service/OrderService.php', 10);

        // 1 method with mi=60
        $this->addMethod($repository, 'App\\Service', 'OrderService', 'process', 'src/Service/OrderService.php', 'mi', 60.0);

        $collector = new MaintainabilityIndexCollector();
        $aggregator = new MetricAggregator($collector->getMetricDefinitions());
        $aggregator->aggregate($repository);

        $nsMetrics = $repository->get(SymbolPath::forNamespace('App\\Service'));

        // Average from raw method values: (80+60)/2 = 70
        self::assertEqualsWithDelta(70.0, $nsMetrics->get('mi.avg'), 0.01);
        // mi.count = 2 (from number of method symbols)
        self::assertSame(2, $nsMetrics->get('mi.count'));
    }

    #[Test]
    public function itHandlesSingleClassNamespaceCorrectly(): void
    {
        $repository = new InMemoryMetricRepository();

        // Single class with 5 methods, all mi=85
        $class1 = SymbolPath::forClass('App\\Single', 'OnlyService');
        $repository->add($class1, (new MetricBag())
            ->with('mi.avg', 85.0)
            ->with('mi.count', 5)
            ->with('mi.min', 75.0), 'src/Single/OnlyService.php', 10);

        // Add 5 method symbols with mi=85 each
        for ($i = 1; $i <= 5; $i++) {
            $this->addMethod($repository, 'App\\Single', 'OnlyService', "method{$i}", 'src/Single/OnlyService.php', 'mi', 85.0);
        }

        $collector = new MaintainabilityIndexCollector();
        $aggregator = new MetricAggregator($collector->getMetricDefinitions());
        $aggregator->aggregate($repository);

        $nsMetrics = $repository->get(SymbolPath::forNamespace('App\\Single'));

        // All methods have mi=85, so avg = 85.0
        self::assertEqualsWithDelta(85.0, $nsMetrics->get('mi.avg'), 0.01);
        self::assertSame(5, $nsMetrics->get('mi.count'));
        // min of all method values = 85.0
        self::assertEqualsWithDelta(85.0, $nsMetrics->get('mi.min'), 0.01);
    }

    #[Test]
    public function itAggregatesAdditiveMethodMetricsFromRawMethodValues(): void
    {
        $repository = new InMemoryMetricRepository();

        // UserService: 4 methods with ccn [5,5,5,5] → sum=20
        $class1 = SymbolPath::forClass('App\\Service', 'UserService');
        $repository->add($class1, (new MetricBag())
            ->with('ccn.sum', 20.0)
            ->with('ccn.avg', 5.0), 'src/Service/UserService.php', 10);

        for ($i = 1; $i <= 4; $i++) {
            $this->addMethod($repository, 'App\\Service', 'UserService', "method{$i}", 'src/Service/UserService.php', 'ccn', 5.0);
        }

        // OrderService: 3 methods with ccn [10,10,10] → sum=30
        $class2 = SymbolPath::forClass('App\\Service', 'OrderService');
        $repository->add($class2, (new MetricBag())
            ->with('ccn.sum', 30.0)
            ->with('ccn.avg', 10.0), 'src/Service/OrderService.php', 10);

        for ($i = 1; $i <= 3; $i++) {
            $this->addMethod($repository, 'App\\Service', 'OrderService', "method{$i}", 'src/Service/OrderService.php', 'ccn', 10.0);
        }

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

        // ccn.sum = sum of all 7 method values = 5*4 + 10*3 = 50
        self::assertEqualsWithDelta(50.0, $nsMetrics->get('ccn.sum'), 0.01);
        // ccn.avg = average of all 7 method values = 50/7 ≈ 7.14
        self::assertEqualsWithDelta(50.0 / 7, $nsMetrics->get('ccn.avg'), 0.01);
    }

    #[Test]
    public function itAdditiveMetricsAggregateFromMethodValuesRegardlessOfClassWeights(): void
    {
        $repository = new InMemoryMetricRepository();

        // UserService: 4 methods with ccn [5,5,5,5] → sum=20
        $class1 = SymbolPath::forClass('App\\Service', 'UserService');
        $repository->add($class1, (new MetricBag())
            ->with('ccn.sum', 20.0)
            ->with('ccn.avg', 5.0)
            ->with('ccn.count', 4), 'src/Service/UserService.php', 10);

        for ($i = 1; $i <= 4; $i++) {
            $this->addMethod($repository, 'App\\Service', 'UserService', "method{$i}", 'src/Service/UserService.php', 'ccn', 5.0);
        }

        // OrderService: 3 methods with ccn [10,10,10] → sum=30
        $class2 = SymbolPath::forClass('App\\Service', 'OrderService');
        $repository->add($class2, (new MetricBag())
            ->with('ccn.sum', 30.0)
            ->with('ccn.avg', 10.0)
            ->with('ccn.count', 3), 'src/Service/OrderService.php', 10);

        for ($i = 1; $i <= 3; $i++) {
            $this->addMethod($repository, 'App\\Service', 'OrderService', "method{$i}", 'src/Service/OrderService.php', 'ccn', 10.0);
        }

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

        // ccn.sum = sum of all 7 method values = 50
        self::assertEqualsWithDelta(50.0, $nsMetrics->get('ccn.sum'), 0.01);
        // ccn.avg = average of all 7 method values = 50/7 ≈ 7.14
        self::assertEqualsWithDelta(50.0 / 7, $nsMetrics->get('ccn.avg'), 0.01);
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

    #[Test]
    public function itUsesRawMethodValuesNotClassBagForNamespaceAggregation(): void
    {
        $repository = new InMemoryMetricRepository();

        // Class bag has ccn.sum=999 (stale/incorrect value)
        // but raw method values are [2, 3] → correct sum=5
        $class = SymbolPath::forClass('App\\Service', 'Svc');
        $repository->add($class, (new MetricBag())
            ->with('ccn.sum', 999)
            ->with('ccn.avg', 499.5)
            ->with('ccn.max', 999)
            ->with('ccn.count', 2), 'src/Service/Svc.php', 10);

        $this->addMethod($repository, 'App\\Service', 'Svc', 'doA', 'src/Service/Svc.php', 'ccn', 2);
        $this->addMethod($repository, 'App\\Service', 'Svc', 'doB', 'src/Service/Svc.php', 'ccn', 3);

        $definition = new MetricDefinition(
            name: 'ccn',
            collectedAt: SymbolLevel::Method,
            aggregations: [
                SymbolLevel::Class_->value => [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max],
                SymbolLevel::Namespace_->value => [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max],
            ],
        );
        $aggregator = new MetricAggregator([$definition]);
        $aggregator->aggregate($repository);

        $nsMetrics = $repository->get(SymbolPath::forNamespace('App\\Service'));

        // Namespace must use raw method values [2, 3], NOT class bag ccn.sum=999
        self::assertEqualsWithDelta(5.0, $nsMetrics->get('ccn.sum'), 0.01, 'sum from raw methods');
        self::assertEqualsWithDelta(2.5, $nsMetrics->get('ccn.avg'), 0.01, 'avg from raw methods');
        self::assertSame(3, (int) $nsMetrics->get('ccn.max'), 'max from raw methods');
    }

    private function addMethod(
        InMemoryMetricRepository $repository,
        string $namespace,
        string $class,
        string $method,
        string $file,
        string $metric,
        int|float $value,
    ): void {
        $repository->add(
            SymbolPath::forMethod($namespace, $class, $method),
            (new MetricBag())->with($metric, $value),
            $file,
            1,
        );
    }
}
