<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Aggregator;

use AiMessDetector\Analysis\Aggregator\AggregationHelper;
use AiMessDetector\Analysis\Aggregator\ClassToNamespaceAggregator;
use AiMessDetector\Analysis\Aggregator\MetricAggregator;
use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricDefinition;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Metrics\Maintainability\MaintainabilityIndexCollector;
use AiMessDetector\Metrics\Size\LocCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassToNamespaceAggregator::class)]
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
    public function itAggregatesNonAdditiveMethodMetricsViaAverageFallback(): void
    {
        $repository = new InMemoryMetricRepository();

        // Two classes with method-level MI already aggregated to class level (avg only, no sum)
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

        // MI uses Average fallback (no Sum at class level)
        // Namespace mi.avg = average of [80.0, 60.0] = 70.0
        self::assertEqualsWithDelta(70.0, $nsMetrics->get('mi.avg'), 0.01);
        // Namespace mi.min = min of [80.0, 60.0] = 60.0
        self::assertEqualsWithDelta(60.0, $nsMetrics->get('mi.min'), 0.01);
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
}
