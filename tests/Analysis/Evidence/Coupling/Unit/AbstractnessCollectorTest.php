<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Coupling\AbstractnessCollector;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\AggregationHelper;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\MetricAggregator;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Evidence\Size\ClassCountCollector;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;

#[CoversClass(AbstractnessCollector::class)]
final class AbstractnessCollectorTest extends TestCase
{
    private AbstractnessCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new AbstractnessCollector();
    }

    #[Test]
    public function getName_returnsAbstractness(): void
    {
        self::assertSame('abstractness', $this->collector->getName());
    }

    #[Test]
    public function requires_returnsRequiredMetrics(): void
    {
        self::assertSame(
            ['classCount.sum', 'enumCount.sum', 'traitCount.sum', 'abstractClassCount.sum', 'interfaceCount.sum'],
            $this->collector->requires(),
        );
    }

    #[Test]
    public function provides_returnsAbstractness(): void
    {
        self::assertSame(['abstractness'], $this->collector->provides());
    }

    #[Test]
    public function calculate_computesAbstractness(): void
    {
        // Namespace with 10 classes + 2 enums + 3 traits + 3 interfaces = 18 total types
        // 2 abstract classes + 3 interfaces = 5 abstractions
        // Abstractness = 5 / 18 = 0.278
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\Domain');

        $metrics = (new MetricBag())
            ->with('classCount.sum', 10)
            ->with('enumCount.sum', 2)
            ->with('traitCount.sum', 3)
            ->with('abstractClassCount.sum', 2)
            ->with('interfaceCount.sum', 3);

        $repository->add($nsPath, $metrics, null, 0);

        $graph = $this->createEmptyGraph();

        $this->collector->calculate($graph, $repository);

        $result = $repository->get($nsPath);
        self::assertEqualsWithDelta(0.278, $result->get('abstractness'), 0.001);
    }

    #[Test]
    public function itCalculatesOneSixthFromExplicitNamespaceCountTotals(): void
    {
        $repository = new InMemoryMetricRepository();
        $namespace = 'App\\Domain';
        $file = RelativePath::fromString('src/Domain/Types.php');

        $repository->add(
            SymbolPath::forFile($file),
            MetricBag::fromArray([
                'classCount' => 6,
                'abstractClassCount' => 1,
                'interfaceCount' => 0,
                'traitCount' => 0,
                'enumCount' => 0,
            ]),
            $file,
            1,
        );
        $repository->add(SymbolPath::forClass($namespace, 'Concrete'), new MetricBag(), $file, 2);
        $repository->add(
            SymbolPath::forNamespace($namespace),
            MetricBag::fromArray([
                'classCount' => 6,
                'classCount.count' => 6,
                'abstractClassCount' => 1,
                'abstractClassCount.count' => 6,
                'interfaceCount' => 0,
                'interfaceCount.count' => 6,
                'traitCount' => 0,
                'traitCount.count' => 6,
                'enumCount' => 0,
                'enumCount.count' => 6,
            ]),
            $file,
            1,
        );

        (new MetricAggregator(AggregationHelper::collectDefinitions([
            new ClassCountCollector(),
        ])))->aggregate($repository);
        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertSame(6, $repository->get(SymbolPath::forNamespace($namespace))->get('classCount.sum'));
        self::assertSame(1, $repository->get(SymbolPath::forNamespace($namespace))->get('abstractClassCount.sum'));
        self::assertEqualsWithDelta(1 / 6, $repository->get(SymbolPath::forNamespace($namespace))->get('abstractness'), 0.000001);
    }

    #[Test]
    public function calculate_fullyConcreteNamespace(): void
    {
        // All concrete types: 5 classes + 2 enums + 1 trait, no abstractions
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\Concrete');

        $metrics = (new MetricBag())
            ->with('classCount.sum', 5)
            ->with('enumCount.sum', 2)
            ->with('traitCount.sum', 1)
            ->with('abstractClassCount.sum', 0)
            ->with('interfaceCount.sum', 0);

        $repository->add($nsPath, $metrics, null, 0);

        $graph = $this->createEmptyGraph();

        $this->collector->calculate($graph, $repository);

        $result = $repository->get($nsPath);
        self::assertEqualsWithDelta(0.0, $result->get('abstractness'), 0.001);
    }

    #[Test]
    public function calculate_fullyAbstractNamespace(): void
    {
        // All types are abstract: 2 abstract classes + 3 interfaces
        // classCount includes abstract classes, so classCount=2
        // totalTypes = 2 + 0 + 0 + 3 = 5
        // totalAbstractions = 2 + 3 = 5
        // Abstractness = 5 / 5 = 1.0
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\Contracts');

        $metrics = (new MetricBag())
            ->with('classCount.sum', 2)
            ->with('enumCount.sum', 0)
            ->with('traitCount.sum', 0)
            ->with('abstractClassCount.sum', 2)
            ->with('interfaceCount.sum', 3);

        $repository->add($nsPath, $metrics, null, 0);

        $graph = $this->createEmptyGraph();

        $this->collector->calculate($graph, $repository);

        $result = $repository->get($nsPath);
        self::assertEqualsWithDelta(1.0, $result->get('abstractness'), 0.001);
    }

    #[Test]
    public function calculate_emptyNamespace_returnsZero(): void
    {
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\Empty');

        $metrics = (new MetricBag())
            ->with('classCount.sum', 0)
            ->with('enumCount.sum', 0)
            ->with('traitCount.sum', 0)
            ->with('abstractClassCount.sum', 0)
            ->with('interfaceCount.sum', 0);

        $repository->add($nsPath, $metrics, null, 0);

        $graph = $this->createEmptyGraph();

        $this->collector->calculate($graph, $repository);

        $result = $repository->get($nsPath);
        self::assertEqualsWithDelta(0.0, $result->get('abstractness'), 0.001);
    }

    #[Test]
    public function calculate_withEnumsAndTraits_preventAbstractnessOverOne(): void
    {
        // Edge case: namespace with 2 interfaces + 6 enums
        // totalTypes = 0 (classes) + 6 (enums) + 0 (traits) + 2 (interfaces) = 8
        // totalAbstractions = 0 (abstract) + 2 (interfaces) = 2
        // A = 2/8 = 0.25
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\Mixed');

        $metrics = (new MetricBag())
            ->with('classCount.sum', 0)
            ->with('enumCount.sum', 6)
            ->with('traitCount.sum', 0)
            ->with('abstractClassCount.sum', 0)
            ->with('interfaceCount.sum', 2);

        $repository->add($nsPath, $metrics, null, 0);

        $graph = $this->createEmptyGraph();

        $this->collector->calculate($graph, $repository);

        $result = $repository->get($nsPath);
        $abstractness = $result->get('abstractness');

        // Must be in [0, 1] range
        self::assertGreaterThanOrEqual(0.0, $abstractness);
        self::assertLessThanOrEqual(1.0, $abstractness);
        self::assertEqualsWithDelta(0.25, $abstractness, 0.001);
    }

    #[Test]
    public function calculate_onlyInterfaces_returnsOne(): void
    {
        // Namespace with only interfaces: 3 interfaces, 0 classes
        // totalTypes = 0 + 0 + 0 + 3 = 3
        // totalAbstractions = 0 + 3 = 3
        // A = 3/3 = 1.0
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\Contracts\\Only');

        $metrics = (new MetricBag())
            ->with('classCount.sum', 0)
            ->with('enumCount.sum', 0)
            ->with('traitCount.sum', 0)
            ->with('abstractClassCount.sum', 0)
            ->with('interfaceCount.sum', 3);

        $repository->add($nsPath, $metrics, null, 0);

        $graph = $this->createEmptyGraph();

        $this->collector->calculate($graph, $repository);

        $result = $repository->get($nsPath);
        self::assertEqualsWithDelta(1.0, $result->get('abstractness'), 0.001);
    }

    #[Test]
    public function calculate_classesAndInterfaces_interfaceCountsInDenominator(): void
    {
        // 2 concrete classes + 1 interface = 3 total types
        // Only the interface is abstract: totalAbstractions = 1
        // A = 1/3 = 0.333
        $repository = new InMemoryMetricRepository();
        $nsPath = SymbolPath::forNamespace('App\\Service');

        $metrics = (new MetricBag())
            ->with('classCount.sum', 2)
            ->with('enumCount.sum', 0)
            ->with('traitCount.sum', 0)
            ->with('abstractClassCount.sum', 0)
            ->with('interfaceCount.sum', 1);

        $repository->add($nsPath, $metrics, null, 0);

        $graph = $this->createEmptyGraph();

        $this->collector->calculate($graph, $repository);

        $result = $repository->get($nsPath);
        self::assertEqualsWithDelta(0.333, $result->get('abstractness'), 0.001);
    }

    private function createEmptyGraph(): DependencyGraphInterface
    {
        return AdjacencyGraphBuilder::empty();
    }
}
