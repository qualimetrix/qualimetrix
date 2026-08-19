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
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
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
    public function itIsNamedAbstractness(): void
    {
        self::assertSame('abstractness', $this->collector->getName());
    }

    #[Test]
    public function itRequiresTheImplementingEnumCountRatherThanTheTotalEnumCount(): void
    {
        self::assertSame(
            ['classCount.sum', 'implementingEnumCount.sum', 'traitCount.sum', 'abstractClassCount.sum', 'interfaceCount.sum'],
            $this->collector->requires(),
        );
    }

    #[Test]
    public function itProvidesAbstractness(): void
    {
        self::assertSame(['abstractness'], $this->collector->provides());
    }

    #[Test]
    public function itKeepsBareEnumsOutOfTheDenominator(): void
    {
        // 10 classes + 3 traits + 3 interfaces = 16 total types; the 2 bare enums are neutral.
        // 2 abstract classes + 3 interfaces = 5 abstractions => 5 / 16 = 0.3125
        $repository = $this->repositoryWithNamespaceCounts('App\\Domain', [
            'classCount.sum' => 10,
            'enumCount.sum' => 2,
            'implementingEnumCount.sum' => 0,
            'traitCount.sum' => 3,
            'abstractClassCount.sum' => 2,
            'interfaceCount.sum' => 3,
        ]);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertEqualsWithDelta(
            0.3125,
            $repository->get(SymbolPath::forNamespace('App\\Domain'))->get('abstractness'),
            0.0001,
        );
    }

    #[Test]
    public function itCountsEnumsImplementingAnInterfaceAsConcrete(): void
    {
        // 10 classes + 3 traits + 3 interfaces + 2 implementing enums = 18 total types
        // 5 abstractions => 5 / 18 = 0.278
        $repository = $this->repositoryWithNamespaceCounts('App\\Domain', [
            'classCount.sum' => 10,
            'enumCount.sum' => 2,
            'implementingEnumCount.sum' => 2,
            'traitCount.sum' => 3,
            'abstractClassCount.sum' => 2,
            'interfaceCount.sum' => 3,
        ]);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertEqualsWithDelta(
            0.278,
            $repository->get(SymbolPath::forNamespace('App\\Domain'))->get('abstractness'),
            0.001,
        );
    }

    #[Test]
    public function itDoesNotReportOneForAnInterfaceImplementedByEnumsOnly(): void
    {
        // One interface plus four enums implementing it: the implementations sit right there,
        // so the namespace must not read as fully abstract. A = 1 / 5 = 0.2
        $repository = $this->repositoryWithNamespaceCounts('App\\Status', [
            'classCount.sum' => 0,
            'enumCount.sum' => 4,
            'implementingEnumCount.sum' => 4,
            'traitCount.sum' => 0,
            'abstractClassCount.sum' => 0,
            'interfaceCount.sum' => 1,
        ]);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        $abstractness = $repository->get(SymbolPath::forNamespace('App\\Status'))->get('abstractness');

        self::assertLessThan(1.0, $abstractness);
        self::assertEqualsWithDelta(0.2, $abstractness, 0.001);
    }

    #[Test]
    public function itReturnsZeroForAnEnumOnlyNamespace(): void
    {
        // Bare enums leave totalTypes at 0, which keeps the pre-existing no-type behaviour.
        $repository = $this->repositoryWithNamespaceCounts('App\\Enums', [
            'classCount.sum' => 0,
            'enumCount.sum' => 5,
            'implementingEnumCount.sum' => 0,
            'traitCount.sum' => 0,
            'abstractClassCount.sum' => 0,
            'interfaceCount.sum' => 0,
        ]);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertSame(0.0, $repository->get(SymbolPath::forNamespace('App\\Enums'))->get('abstractness'));
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
                'implementingEnumCount' => 0,
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
                'implementingEnumCount' => 0,
                'implementingEnumCount.count' => 6,
            ]),
            $file,
            1,
        );

        (new MetricAggregator(AggregationHelper::collectDefinitions([
            new ClassCountCollector(),
        ]), self::createStub(ProfilerInterface::class)))->aggregate($repository);
        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertSame(6, $repository->get(SymbolPath::forNamespace($namespace))->get('classCount.sum'));
        self::assertSame(1, $repository->get(SymbolPath::forNamespace($namespace))->get('abstractClassCount.sum'));
        self::assertEqualsWithDelta(1 / 6, $repository->get(SymbolPath::forNamespace($namespace))->get('abstractness'), 0.000001);
    }

    #[Test]
    public function itReturnsZeroForAFullyConcreteNamespace(): void
    {
        $repository = $this->repositoryWithNamespaceCounts('App\\Concrete', [
            'classCount.sum' => 5,
            'implementingEnumCount.sum' => 2,
            'traitCount.sum' => 1,
            'abstractClassCount.sum' => 0,
            'interfaceCount.sum' => 0,
        ]);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertEqualsWithDelta(
            0.0,
            $repository->get(SymbolPath::forNamespace('App\\Concrete'))->get('abstractness'),
            0.001,
        );
    }

    #[Test]
    public function itReturnsOneForANamespaceOfAbstractClassesAndInterfaces(): void
    {
        // classCount includes abstract classes: totalTypes = 2 + 3 = 5, abstractions = 2 + 3 = 5
        $repository = $this->repositoryWithNamespaceCounts('App\\Contracts', [
            'classCount.sum' => 2,
            'implementingEnumCount.sum' => 0,
            'traitCount.sum' => 0,
            'abstractClassCount.sum' => 2,
            'interfaceCount.sum' => 3,
        ]);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertEqualsWithDelta(
            1.0,
            $repository->get(SymbolPath::forNamespace('App\\Contracts'))->get('abstractness'),
            0.001,
        );
    }

    #[Test]
    public function itReturnsZeroForANamespaceWithoutAnyType(): void
    {
        $repository = $this->repositoryWithNamespaceCounts('App\\Empty', [
            'classCount.sum' => 0,
            'implementingEnumCount.sum' => 0,
            'traitCount.sum' => 0,
            'abstractClassCount.sum' => 0,
            'interfaceCount.sum' => 0,
        ]);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertEqualsWithDelta(
            0.0,
            $repository->get(SymbolPath::forNamespace('App\\Empty'))->get('abstractness'),
            0.001,
        );
    }

    #[Test]
    public function itClampsTheResultToTheUnitRange(): void
    {
        // 2 interfaces + 6 implementing enums => 2 / 8 = 0.25
        $repository = $this->repositoryWithNamespaceCounts('App\\Mixed', [
            'classCount.sum' => 0,
            'implementingEnumCount.sum' => 6,
            'traitCount.sum' => 0,
            'abstractClassCount.sum' => 0,
            'interfaceCount.sum' => 2,
        ]);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        $abstractness = $repository->get(SymbolPath::forNamespace('App\\Mixed'))->get('abstractness');

        self::assertGreaterThanOrEqual(0.0, $abstractness);
        self::assertLessThanOrEqual(1.0, $abstractness);
        self::assertEqualsWithDelta(0.25, $abstractness, 0.001);
    }

    #[Test]
    public function itReturnsOneForAnInterfaceOnlyNamespace(): void
    {
        $repository = $this->repositoryWithNamespaceCounts('App\\Contracts\\Only', [
            'classCount.sum' => 0,
            'implementingEnumCount.sum' => 0,
            'traitCount.sum' => 0,
            'abstractClassCount.sum' => 0,
            'interfaceCount.sum' => 3,
        ]);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertEqualsWithDelta(
            1.0,
            $repository->get(SymbolPath::forNamespace('App\\Contracts\\Only'))->get('abstractness'),
            0.001,
        );
    }

    #[Test]
    public function itCountsInterfacesInBothNumeratorAndDenominator(): void
    {
        $repository = $this->repositoryWithNamespaceCounts('App\\Service', [
            'classCount.sum' => 2,
            'implementingEnumCount.sum' => 0,
            'traitCount.sum' => 0,
            'abstractClassCount.sum' => 0,
            'interfaceCount.sum' => 1,
        ]);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertEqualsWithDelta(
            0.333,
            $repository->get(SymbolPath::forNamespace('App\\Service'))->get('abstractness'),
            0.001,
        );
    }

    /**
     * @param array<string, int> $counts
     */
    private function repositoryWithNamespaceCounts(string $namespace, array $counts): InMemoryMetricRepository
    {
        $repository = new InMemoryMetricRepository();
        $metrics = new MetricBag();

        foreach ($counts as $name => $value) {
            $metrics = $metrics->with($name, $value);
        }

        $repository->add(SymbolPath::forNamespace($namespace), $metrics, null, 0);

        return $repository;
    }

    private function createEmptyGraph(): DependencyGraphInterface
    {
        return AdjacencyGraphBuilder::empty();
    }
}
