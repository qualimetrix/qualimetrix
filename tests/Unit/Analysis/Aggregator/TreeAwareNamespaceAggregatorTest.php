<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Aggregator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Aggregator\TreeAwareNamespaceAggregator;
use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Core\Namespace_\NamespaceTree;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(TreeAwareNamespaceAggregator::class)]
final class TreeAwareNamespaceAggregatorTest extends TestCase
{
    #[Test]
    public function parent_gets_correct_sum_from_descendant_leaves(): void
    {
        $repository = new InMemoryMetricRepository();

        // Simulate class symbols in two leaf namespaces
        $this->addClassWithCcn($repository, 'App\\Service', 'UserService', 'src/Service/UserService.php', 5);
        $this->addClassWithCcn($repository, 'App\\Domain', 'Entity', 'src/Domain/Entity.php', 3);

        // Add file symbols with classCount (File-level metric)
        $this->addFileSymbol($repository, 'src/Service/UserService.php', ['classCount' => 1]);
        $this->addFileSymbol($repository, 'src/Domain/Entity.php', ['classCount' => 1]);

        // Namespace bags (as ClassToNamespaceAggregator would produce)
        $this->addNamespaceBag($repository, 'App\\Service', ['ccn.sum' => 5.0]);
        $this->addNamespaceBag($repository, 'App\\Domain', ['ccn.sum' => 3.0]);

        $tree = new NamespaceTree(['App\\Service', 'App\\Domain']);
        $definitions = $this->createDefinitions();

        $aggregator = new TreeAwareNamespaceAggregator($tree);
        $aggregator->aggregate($repository, $definitions);

        $parentMetrics = $repository->get(SymbolPath::forNamespace('App'));

        // Sum from two classes: 5 + 3 = 8
        self::assertEquals(8, $parentMetrics->get('ccn.sum'));
        // Two classes total
        self::assertEquals(2, $parentMetrics->get('classCount.sum'));
    }

    #[Test]
    public function parent_gets_correct_avg_from_raw_symbols(): void
    {
        $repository = new InMemoryMetricRepository();

        // App\Service has 3 methods with CCN: 2, 4, 6 → sum=12, avg=4
        $this->addMethodMetric($repository, 'App\\Service', 'Svc', 'doA', 'src/S/Svc.php', 'ccn', 2);
        $this->addMethodMetric($repository, 'App\\Service', 'Svc', 'doB', 'src/S/Svc.php', 'ccn', 4);
        $this->addMethodMetric($repository, 'App\\Service', 'Svc', 'doC', 'src/S/Svc.php', 'ccn', 6);

        // Simulate class-level aggregation: Svc has ccn.sum=12, ccn.avg=4
        $repository->add(
            SymbolPath::forClass('App\\Service', 'Svc'),
            (new MetricBag())->with('ccn.sum', 12)->with('ccn.avg', 4.0)->with('ccn.count', 3),
            'src/S/Svc.php',
            1,
        );

        // App\Domain has 1 method with CCN: 10 → sum=10, avg=10
        $this->addMethodMetric($repository, 'App\\Domain', 'Ent', 'calc', 'src/D/Ent.php', 'ccn', 10);
        $repository->add(
            SymbolPath::forClass('App\\Domain', 'Ent'),
            (new MetricBag())->with('ccn.sum', 10)->with('ccn.avg', 10.0)->with('ccn.count', 1),
            'src/D/Ent.php',
            1,
        );

        // Simulate namespace bags (as ClassToNamespaceAggregator would)
        $this->addNamespaceBag($repository, 'App\\Service', ['ccn.sum' => 12.0, 'ccn.avg' => 4.0]);
        $this->addNamespaceBag($repository, 'App\\Domain', ['ccn.sum' => 10.0, 'ccn.avg' => 10.0]);

        $tree = new NamespaceTree(['App\\Service', 'App\\Domain']);
        $definitions = [
            new MetricDefinition(
                name: 'ccn',
                collectedAt: SymbolLevel::Method,
                aggregations: [
                    SymbolLevel::Class_->value => [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max],
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max],
                ],
            ),
        ];

        $aggregator = new TreeAwareNamespaceAggregator($tree);
        $aggregator->aggregate($repository, $definitions);

        $parentMetrics = $repository->get(SymbolPath::forNamespace('App'));

        // Sum from both classes: 12 + 10 = 22
        self::assertEquals(22, $parentMetrics->get('ccn.sum'));
        // Weighted average: (12*1 + 10*1) / 2 = 11 (each class contributes its .sum)
        // Actually: collectNamespaceMetricValues reads ccn.sum from classes → [12, 10]
        // Then applies Average → (12 + 10) / 2 = 11
        self::assertEqualsWithDelta(11.0, $parentMetrics->get('ccn.avg'), 0.01);
        // Max: max(12, 10) = 12
        self::assertEquals(12, $parentMetrics->get('ccn.max'));
    }

    #[Test]
    public function deep_hierarchy_aggregates_from_leaf_descendants(): void
    {
        $repository = new InMemoryMetricRepository();

        $this->addClassWithCcn($repository, 'A\\B\\C', 'Foo', 'src/A/B/C/Foo.php', 4);
        $this->addFileSymbol($repository, 'src/A/B/C/Foo.php', ['classCount' => 1]);
        $this->addNamespaceBag($repository, 'A\\B\\C', ['ccn.sum' => 4.0]);

        $tree = new NamespaceTree(['A\\B\\C']);
        $definitions = $this->createDefinitions();

        $aggregator = new TreeAwareNamespaceAggregator($tree);
        $aggregator->aggregate($repository, $definitions);

        // A\B should have same values as A\B\C (single leaf descendant)
        $abMetrics = $repository->get(SymbolPath::forNamespace('A\\B'));
        self::assertEquals(4, $abMetrics->get('ccn.sum'));
        self::assertEquals(1, $abMetrics->get('classCount.sum'));

        // A should also have same values
        $aMetrics = $repository->get(SymbolPath::forNamespace('A'));
        self::assertEquals(4, $aMetrics->get('ccn.sum'));
        self::assertEquals(1, $aMetrics->get('classCount.sum'));
    }

    #[Test]
    public function parent_with_own_symbols_includes_them_in_aggregation(): void
    {
        $repository = new InMemoryMetricRepository();

        // App has its own class (2 classes direct)
        $repository->add(
            SymbolPath::forClass('App', 'Bootstrap'),
            (new MetricBag())->with('ccn.sum', 3),
            'src/Bootstrap.php',
            1,
        );
        $repository->add(
            SymbolPath::forClass('App', 'Kernel'),
            (new MetricBag())->with('ccn.sum', 7),
            'src/Kernel.php',
            1,
        );
        $this->addFileSymbol($repository, 'src/Bootstrap.php', ['classCount' => 1]);
        $this->addFileSymbol($repository, 'src/Kernel.php', ['classCount' => 1]);
        $this->addNamespaceBag($repository, 'App', ['ccn.sum' => 10.0]);

        // App\Service has its own class
        $this->addClassWithCcn($repository, 'App\\Service', 'UserService', 'src/Service/UserService.php', 5);
        $this->addFileSymbol($repository, 'src/Service/UserService.php', ['classCount' => 1]);
        $this->addNamespaceBag($repository, 'App\\Service', ['ccn.sum' => 5.0]);

        // App is a parent (has child App\Service) but ALSO has own symbols
        $tree = new NamespaceTree(['App', 'App\\Service']);
        $definitions = $this->createDefinitions();

        $aggregator = new TreeAwareNamespaceAggregator($tree);
        $aggregator->aggregate($repository, $definitions);

        $appMetrics = $repository->get(SymbolPath::forNamespace('App'));

        // App should include its own classes (2) + App\Service classes (1) = 3
        self::assertEquals(3, $appMetrics->get('classCount.sum'));
        // CCN sum: 3 + 7 + 5 = 15
        self::assertEquals(15, $appMetrics->get('ccn.sum'));
        // Symbol counts: 3 classes total (Bootstrap, Kernel, UserService)
        self::assertSame(3, $appMetrics->get('symbolClassCount'));
    }

    #[Test]
    public function no_parent_namespaces_does_nothing(): void
    {
        $repository = new InMemoryMetricRepository();

        $this->addNamespaceBag($repository, 'App', ['ccn.sum' => 5.0]);

        $tree = new NamespaceTree(['App']);
        $definitions = $this->createDefinitions();

        $namespacesBefore = $repository->getNamespaces();

        $aggregator = new TreeAwareNamespaceAggregator($tree);
        $aggregator->aggregate($repository, $definitions);

        self::assertSame($namespacesBefore, $repository->getNamespaces());
    }

    #[Test]
    public function metric_definition_driven_no_hardcoded_metrics(): void
    {
        $repository = new InMemoryMetricRepository();

        // Custom metric "foo" collected at File level
        $repository->add(
            SymbolPath::forClass('App\\Service', 'Svc'),
            (new MetricBag())->with('foo', 42),
            'src/Svc.php',
            1,
        );
        $repository->add(
            SymbolPath::forFile('src/Svc.php'),
            (new MetricBag())->with('foo', 42),
            'src/Svc.php',
            null,
        );
        $this->addNamespaceBag($repository, 'App\\Service', ['foo.sum' => 42.0]);

        $tree = new NamespaceTree(['App\\Service']);
        // No parent namespaces → nothing to do, but the point is:
        // We don't need a hardcoded list — MetricDefinition drives aggregation

        // Create a definition for a custom metric
        $definitions = [
            new MetricDefinition(
                name: 'foo',
                collectedAt: SymbolLevel::File,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
                ],
            ),
        ];

        // Add a child namespace to trigger parent creation
        $repository->add(
            SymbolPath::forClass('App\\Service\\Sub', 'Bar'),
            (new MetricBag())->with('foo', 10),
            'src/Sub/Bar.php',
            1,
        );
        $repository->add(
            SymbolPath::forFile('src/Sub/Bar.php'),
            (new MetricBag())->with('foo', 10),
            'src/Sub/Bar.php',
            null,
        );
        $this->addNamespaceBag($repository, 'App\\Service\\Sub', ['foo.sum' => 10.0]);

        $tree = new NamespaceTree(['App\\Service', 'App\\Service\\Sub']);
        $aggregator = new TreeAwareNamespaceAggregator($tree);
        $aggregator->aggregate($repository, $definitions);

        // App should get foo.sum = 42 + 10 = 52 from file symbols
        $appMetrics = $repository->get(SymbolPath::forNamespace('App'));
        self::assertEquals(52, $appMetrics->get('foo.sum'));
    }

    #[Test]
    public function symbol_counts_are_computed_for_parent(): void
    {
        $repository = new InMemoryMetricRepository();

        // Two classes in App\Service
        $repository->add(
            SymbolPath::forClass('App\\Service', 'Svc1'),
            new MetricBag(),
            'src/S/Svc1.php',
            1,
        );
        $repository->add(
            SymbolPath::forClass('App\\Service', 'Svc2'),
            new MetricBag(),
            'src/S/Svc2.php',
            1,
        );
        $repository->add(
            SymbolPath::forMethod('App\\Service', 'Svc1', 'doIt'),
            new MetricBag(),
            'src/S/Svc1.php',
            5,
        );
        $this->addNamespaceBag($repository, 'App\\Service', []);

        $tree = new NamespaceTree(['App\\Service']);
        $definitions = $this->createDefinitions();

        $aggregator = new TreeAwareNamespaceAggregator($tree);
        $aggregator->aggregate($repository, $definitions);

        $appMetrics = $repository->get(SymbolPath::forNamespace('App'));

        // 2 classes
        self::assertSame(2, $appMetrics->get('symbolClassCount'));
        // 1 method
        self::assertSame(1, $appMetrics->get('symbolMethodCount'));
    }

    /**
     * Helper: add a class symbol with a CCN metric.
     */
    private function addClassWithCcn(
        InMemoryMetricRepository $repository,
        string $namespace,
        string $class,
        string $file,
        int $ccn,
    ): void {
        $repository->add(
            SymbolPath::forClass($namespace, $class),
            (new MetricBag())->with('ccn.sum', $ccn),
            $file,
            1,
        );
    }

    /**
     * Helper: add a method-level metric.
     */
    private function addMethodMetric(
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

    /**
     * Helper: add a namespace-level metric bag.
     *
     * @param array<string, float> $metrics
     */
    private function addNamespaceBag(
        InMemoryMetricRepository $repository,
        string $namespace,
        array $metrics,
    ): void {
        $bag = new MetricBag();

        foreach ($metrics as $key => $value) {
            $bag = $bag->with($key, $value);
        }

        $symbols = $repository->forNamespace($namespace);
        $firstFile = $symbols !== [] ? $symbols[0]->file : 'unknown.php';

        $repository->add(
            SymbolPath::forNamespace($namespace),
            $bag,
            $firstFile,
            null,
        );
    }

    /**
     * Helper: add a file-level symbol with metrics.
     *
     * @param array<string, int|float> $metrics
     */
    private function addFileSymbol(
        InMemoryMetricRepository $repository,
        string $file,
        array $metrics,
    ): void {
        $bag = new MetricBag();

        foreach ($metrics as $key => $value) {
            $bag = $bag->with($key, $value);
        }

        $repository->add(
            SymbolPath::forFile($file),
            $bag,
            $file,
            null,
        );
    }

    /**
     * Helper: create standard definitions for testing.
     *
     * @return list<MetricDefinition>
     */
    private function createDefinitions(): array
    {
        return [
            new MetricDefinition(
                name: 'ccn',
                collectedAt: SymbolLevel::Method,
                aggregations: [
                    SymbolLevel::Class_->value => [AggregationStrategy::Sum],
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
                ],
            ),
            new MetricDefinition(
                name: 'classCount',
                collectedAt: SymbolLevel::File,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
                ],
            ),
        ];
    }
}
