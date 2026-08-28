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
            ['size.class-count.sum', 'size.implementing-enum-count.sum', 'size.trait-count.sum', 'size.abstract-class-count.sum', 'size.interface-count.sum'],
            $this->collector->requires(),
        );
    }

    #[Test]
    public function itProvidesAbstractness(): void
    {
        self::assertSame(['coupling.abstractness'], $this->collector->provides());
    }

    #[Test]
    public function itKeepsBareEnumsOutOfTheDenominator(): void
    {
        // 10 classes + 3 traits + 3 interfaces = 16 total types; the 2 bare enums are neutral.
        // 2 abstract classes + 3 interfaces = 5 abstractions => 5 / 16 = 0.3125
        $repository = $this->repositoryWithNamespaceCounts('App\\Domain', [
            'size.class-count.sum' => 10,
            'size.enum-count.sum' => 2,
            'size.implementing-enum-count.sum' => 0,
            'size.trait-count.sum' => 3,
            'size.abstract-class-count.sum' => 2,
            'size.interface-count.sum' => 3,
        ]);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertEqualsWithDelta(
            0.3125,
            $repository->get(SymbolPath::forNamespace('App\\Domain'))->get('coupling.abstractness'),
            0.0001,
        );
    }

    #[Test]
    public function itCountsEnumsImplementingAnInterfaceAsConcrete(): void
    {
        // 10 classes + 3 traits + 3 interfaces + 2 implementing enums = 18 total types
        // 5 abstractions => 5 / 18 = 0.278
        $repository = $this->repositoryWithNamespaceCounts('App\\Domain', [
            'size.class-count.sum' => 10,
            'size.enum-count.sum' => 2,
            'size.implementing-enum-count.sum' => 2,
            'size.trait-count.sum' => 3,
            'size.abstract-class-count.sum' => 2,
            'size.interface-count.sum' => 3,
        ]);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertEqualsWithDelta(
            0.278,
            $repository->get(SymbolPath::forNamespace('App\\Domain'))->get('coupling.abstractness'),
            0.001,
        );
    }

    #[Test]
    public function itDoesNotReportOneForAnInterfaceImplementedByEnumsOnly(): void
    {
        // One interface plus four enums implementing it: the implementations sit right there,
        // so the namespace must not read as fully abstract. A = 1 / 5 = 0.2
        $repository = $this->repositoryWithNamespaceCounts('App\\Status', [
            'size.class-count.sum' => 0,
            'size.enum-count.sum' => 4,
            'size.implementing-enum-count.sum' => 4,
            'size.trait-count.sum' => 0,
            'size.abstract-class-count.sum' => 0,
            'size.interface-count.sum' => 1,
        ]);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        $abstractness = $repository->get(SymbolPath::forNamespace('App\\Status'))->get('coupling.abstractness');

        self::assertLessThan(1.0, $abstractness);
        self::assertEqualsWithDelta(0.2, $abstractness, 0.001);
    }

    #[Test]
    public function itReturnsZeroForAnEnumOnlyNamespace(): void
    {
        // Bare enums leave totalTypes at 0, which keeps the pre-existing no-type behaviour.
        $repository = $this->repositoryWithNamespaceCounts('App\\Enums', [
            'size.class-count.sum' => 0,
            'size.enum-count.sum' => 5,
            'size.implementing-enum-count.sum' => 0,
            'size.trait-count.sum' => 0,
            'size.abstract-class-count.sum' => 0,
            'size.interface-count.sum' => 0,
        ]);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertSame(0.0, $repository->get(SymbolPath::forNamespace('App\\Enums'))->get('coupling.abstractness'));
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
                'size.class-count' => 6,
                'size.abstract-class-count' => 1,
                'size.interface-count' => 0,
                'size.trait-count' => 0,
                'size.enum-count' => 0,
                'size.implementing-enum-count' => 0,
            ]),
            $file,
            1,
        );
        $repository->add(SymbolPath::forClass($namespace, 'Concrete'), new MetricBag(), $file, 2);
        $repository->add(
            SymbolPath::forNamespace($namespace),
            MetricBag::fromArray([
                'size.class-count' => 6,
                'size.class-count.count' => 6,
                'size.abstract-class-count' => 1,
                'size.abstract-class-count.count' => 6,
                'size.interface-count' => 0,
                'size.interface-count.count' => 6,
                'size.trait-count' => 0,
                'size.trait-count.count' => 6,
                'size.enum-count' => 0,
                'size.enum-count.count' => 6,
                'size.implementing-enum-count' => 0,
                'size.implementing-enum-count.count' => 6,
            ]),
            $file,
            1,
        );

        (new MetricAggregator(AggregationHelper::collectDefinitions([
            new ClassCountCollector(),
        ]), self::createStub(ProfilerInterface::class)))->aggregate($repository);
        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertSame(6, $repository->get(SymbolPath::forNamespace($namespace))->get('size.class-count.sum'));
        self::assertSame(1, $repository->get(SymbolPath::forNamespace($namespace))->get('size.abstract-class-count.sum'));
        self::assertEqualsWithDelta(1 / 6, $repository->get(SymbolPath::forNamespace($namespace))->get('coupling.abstractness'), 0.000001);
    }

    #[Test]
    public function itReturnsZeroForAFullyConcreteNamespace(): void
    {
        $repository = $this->repositoryWithNamespaceCounts('App\\Concrete', [
            'size.class-count.sum' => 5,
            'size.implementing-enum-count.sum' => 2,
            'size.trait-count.sum' => 1,
            'size.abstract-class-count.sum' => 0,
            'size.interface-count.sum' => 0,
        ]);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertEqualsWithDelta(
            0.0,
            $repository->get(SymbolPath::forNamespace('App\\Concrete'))->get('coupling.abstractness'),
            0.001,
        );
    }

    #[Test]
    public function itReturnsOneForANamespaceOfAbstractClassesAndInterfaces(): void
    {
        // classCount includes abstract classes: totalTypes = 2 + 3 = 5, abstractions = 2 + 3 = 5
        $repository = $this->repositoryWithNamespaceCounts('App\\Contracts', [
            'size.class-count.sum' => 2,
            'size.implementing-enum-count.sum' => 0,
            'size.trait-count.sum' => 0,
            'size.abstract-class-count.sum' => 2,
            'size.interface-count.sum' => 3,
        ]);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertEqualsWithDelta(
            1.0,
            $repository->get(SymbolPath::forNamespace('App\\Contracts'))->get('coupling.abstractness'),
            0.001,
        );
    }

    #[Test]
    public function itReturnsZeroForANamespaceWithoutAnyType(): void
    {
        $repository = $this->repositoryWithNamespaceCounts('App\\Empty', [
            'size.class-count.sum' => 0,
            'size.implementing-enum-count.sum' => 0,
            'size.trait-count.sum' => 0,
            'size.abstract-class-count.sum' => 0,
            'size.interface-count.sum' => 0,
        ]);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertEqualsWithDelta(
            0.0,
            $repository->get(SymbolPath::forNamespace('App\\Empty'))->get('coupling.abstractness'),
            0.001,
        );
    }

    #[Test]
    public function itClampsTheResultToTheUnitRange(): void
    {
        // 2 interfaces + 6 implementing enums => 2 / 8 = 0.25
        $repository = $this->repositoryWithNamespaceCounts('App\\Mixed', [
            'size.class-count.sum' => 0,
            'size.implementing-enum-count.sum' => 6,
            'size.trait-count.sum' => 0,
            'size.abstract-class-count.sum' => 0,
            'size.interface-count.sum' => 2,
        ]);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        $abstractness = $repository->get(SymbolPath::forNamespace('App\\Mixed'))->get('coupling.abstractness');

        self::assertGreaterThanOrEqual(0.0, $abstractness);
        self::assertLessThanOrEqual(1.0, $abstractness);
        self::assertEqualsWithDelta(0.25, $abstractness, 0.001);
    }

    #[Test]
    public function itReturnsOneForAnInterfaceOnlyNamespace(): void
    {
        $repository = $this->repositoryWithNamespaceCounts('App\\Contracts\\Only', [
            'size.class-count.sum' => 0,
            'size.implementing-enum-count.sum' => 0,
            'size.trait-count.sum' => 0,
            'size.abstract-class-count.sum' => 0,
            'size.interface-count.sum' => 3,
        ]);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertEqualsWithDelta(
            1.0,
            $repository->get(SymbolPath::forNamespace('App\\Contracts\\Only'))->get('coupling.abstractness'),
            0.001,
        );
    }

    #[Test]
    public function itCountsInterfacesInBothNumeratorAndDenominator(): void
    {
        $repository = $this->repositoryWithNamespaceCounts('App\\Service', [
            'size.class-count.sum' => 2,
            'size.implementing-enum-count.sum' => 0,
            'size.trait-count.sum' => 0,
            'size.abstract-class-count.sum' => 0,
            'size.interface-count.sum' => 1,
        ]);

        $this->collector->calculate($this->createEmptyGraph(), $repository);

        self::assertEqualsWithDelta(
            0.333,
            $repository->get(SymbolPath::forNamespace('App\\Service'))->get('coupling.abstractness'),
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
