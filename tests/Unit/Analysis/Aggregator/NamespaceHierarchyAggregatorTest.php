<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Aggregator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Aggregator\NamespaceHierarchyAggregator;
use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(NamespaceHierarchyAggregator::class)]
final class NamespaceHierarchyAggregatorTest extends TestCase
{
    #[Test]
    public function parent_gets_sum_of_child_class_count_and_loc(): void
    {
        $repository = new InMemoryMetricRepository();

        $repository->add(
            SymbolPath::forNamespace('App\\Service'),
            (new MetricBag())
                ->with('classCount.sum', 5)
                ->with('loc.sum', 100),
            'src/Service/UserService.php',
            null,
        );

        $repository->add(
            SymbolPath::forNamespace('App\\Domain'),
            (new MetricBag())
                ->with('classCount.sum', 3)
                ->with('loc.sum', 50),
            'src/Domain/Entity.php',
            null,
        );

        $aggregator = new NamespaceHierarchyAggregator();
        $aggregator->aggregate($repository, []);

        $parentMetrics = $repository->get(SymbolPath::forNamespace('App'));

        self::assertSame(8.0, $parentMetrics->get('classCount.sum'));
        self::assertSame(150.0, $parentMetrics->get('loc.sum'));
    }

    #[Test]
    public function leaf_and_parent_merge(): void
    {
        $repository = new InMemoryMetricRepository();

        // App already exists as a leaf namespace with its own classCount
        $repository->add(
            SymbolPath::forNamespace('App'),
            (new MetricBag())->with('classCount.sum', 2)->with('loc.sum', 30),
            'src/App.php',
            null,
        );

        // App\Service is a child namespace
        $repository->add(
            SymbolPath::forNamespace('App\\Service'),
            (new MetricBag())->with('classCount.sum', 5)->with('loc.sum', 100),
            'src/Service/UserService.php',
            null,
        );

        $aggregator = new NamespaceHierarchyAggregator();
        $aggregator->aggregate($repository, []);

        $parentMetrics = $repository->get(SymbolPath::forNamespace('App'));

        // Parent's own metrics (2) + child metrics (5) = 7
        self::assertSame(7.0, $parentMetrics->get('classCount.sum'));
        self::assertSame(130.0, $parentMetrics->get('loc.sum'));
    }

    #[Test]
    public function deep_nesting_propagates_metrics_up(): void
    {
        $repository = new InMemoryMetricRepository();

        $repository->add(
            SymbolPath::forNamespace('A\\B\\C'),
            (new MetricBag())
                ->with('classCount.sum', 4)
                ->with('loc.sum', 200),
            'src/A/B/C/Foo.php',
            null,
        );

        $aggregator = new NamespaceHierarchyAggregator();
        $aggregator->aggregate($repository, []);

        $abMetrics = $repository->get(SymbolPath::forNamespace('A\\B'));
        self::assertSame(4.0, $abMetrics->get('classCount.sum'));
        self::assertSame(200.0, $abMetrics->get('loc.sum'));

        $aMetrics = $repository->get(SymbolPath::forNamespace('A'));
        self::assertSame(4.0, $aMetrics->get('classCount.sum'));
        self::assertSame(200.0, $aMetrics->get('loc.sum'));
    }

    #[Test]
    public function no_parent_namespaces_needed_for_single_segment(): void
    {
        $repository = new InMemoryMetricRepository();

        $repository->add(
            SymbolPath::forNamespace('App'),
            (new MetricBag())->with('classCount.sum', 3),
            'src/App.php',
            null,
        );

        $repository->add(
            SymbolPath::forNamespace('Vendor'),
            (new MetricBag())->with('classCount.sum', 7),
            'src/Vendor.php',
            null,
        );

        $aggregator = new NamespaceHierarchyAggregator();
        $aggregator->aggregate($repository, []);

        // No new namespaces should be created — only App and Vendor exist
        $namespaces = $repository->getNamespaces();
        self::assertSame(['App', 'Vendor'], $namespaces);
    }

    #[Test]
    public function all_count_metrics_are_summed(): void
    {
        $repository = new InMemoryMetricRepository();

        $repository->add(
            SymbolPath::forNamespace('App\\Service'),
            (new MetricBag())
                ->with('classCount.sum', 5)
                ->with('abstractClassCount.sum', 1)
                ->with('interfaceCount.sum', 2)
                ->with('enumCount.sum', 1)
                ->with('traitCount.sum', 1)
                ->with('loc.sum', 100)
                ->with('symbolMethodCount', 20)
                ->with('symbolClassCount', 5),
            'src/Service/UserService.php',
            null,
        );

        $repository->add(
            SymbolPath::forNamespace('App\\Domain'),
            (new MetricBag())
                ->with('classCount.sum', 3)
                ->with('abstractClassCount.sum', 2)
                ->with('interfaceCount.sum', 1)
                ->with('enumCount.sum', 0)
                ->with('traitCount.sum', 1)
                ->with('loc.sum', 50)
                ->with('symbolMethodCount', 10)
                ->with('symbolClassCount', 3),
            'src/Domain/Entity.php',
            null,
        );

        $aggregator = new NamespaceHierarchyAggregator();
        $aggregator->aggregate($repository, []);

        $parentMetrics = $repository->get(SymbolPath::forNamespace('App'));

        self::assertSame(8.0, $parentMetrics->get('classCount.sum'));
        self::assertSame(3.0, $parentMetrics->get('abstractClassCount.sum'));
        self::assertSame(3.0, $parentMetrics->get('interfaceCount.sum'));
        self::assertSame(1.0, $parentMetrics->get('enumCount.sum'));
        self::assertSame(2.0, $parentMetrics->get('traitCount.sum'));
        self::assertSame(150.0, $parentMetrics->get('loc.sum'));
        self::assertSame(30.0, $parentMetrics->get('symbolMethodCount'));
        self::assertSame(8.0, $parentMetrics->get('symbolClassCount'));
    }
}
