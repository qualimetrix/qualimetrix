<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Unit\Inheritance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\NocCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;

#[CoversClass(NocCollector::class)]
final class NocCollectorTest extends TestCase
{
    private NocCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new NocCollector();
    }

    /**
     * Helper method to create an extends dependency.
     */
    private function createExtends(string $childClass, string $parentClass, string $file = 'test.php', int $line = 1, bool $describesNestedAnonymousClass = false): Dependency
    {
        return new Dependency(
            source: DeclarationPath::of(SymbolPath::fromClassFqn($childClass), RelativePath::fromString($file), DeclarationOrdinal::fromRank(0)),
            target: new LogicalClassPath(SymbolPath::fromClassFqn($parentClass)),
            type: DependencyType::Extends,
            location: new Location(RelativePath::fromString($file), $line),
            describesNestedAnonymousClass: $describesNestedAnonymousClass,
        );
    }

    #[Test]
    public function itReturnsNocAsItsName(): void
    {
        self::assertSame('noc', $this->collector->getName());
    }

    #[Test]
    public function itRequiresNoOtherMetrics(): void
    {
        self::assertSame([], $this->collector->requires());
    }

    #[Test]
    public function itProvidesTheDesignNocMetric(): void
    {
        self::assertSame(['design.noc'], $this->collector->provides());
    }

    #[Test]
    public function itAssignsNocZeroToAClassWithoutChildren(): void
    {
        // Leaf class with no children
        $repository = new InMemoryMetricRepository();
        $graph = $this->graph([]);

        // Add leaf class without parent
        $leafPath = SymbolPath::forClass('App', 'LeafClass');
        $repository->add($leafPath, new MetricBag(), RelativePath::fromString('test.php'), 10);

        $this->collector->calculate($graph, $repository);

        $metrics = $repository->get($leafPath);
        self::assertSame(0, $metrics->get('design.noc'));
    }

    #[Test]
    public function itAssignsNocOneToAClassWithOneChild(): void
    {
        // Parent class with one child
        $repository = new InMemoryMetricRepository();

        // Create dependency graph with extends relationship
        $extends = $this->createExtends('App\\ChildClass', 'App\\BaseClass', 'child.php', 20);
        $graph = $this->graph([$extends]);

        // Add parent class
        $parentPath = SymbolPath::forClass('App', 'BaseClass');
        $repository->add($parentPath, new MetricBag(), RelativePath::fromString('base.php'), 10);

        // Add child class
        $childPath = SymbolPath::forClass('App', 'ChildClass');
        $repository->add($childPath, new MetricBag(), RelativePath::fromString('child.php'), 20);

        $this->collector->calculate($graph, $repository);

        $parentMetrics = $repository->get($parentPath);
        self::assertSame(1, $parentMetrics->get('design.noc'));

        $childMetricsAfter = $repository->get($childPath);
        self::assertSame(0, $childMetricsAfter->get('design.noc'));
    }

    #[Test]
    public function itAssignsNocTwoToAClassWithTwoChildren(): void
    {
        // Parent class with two direct children
        $repository = new InMemoryMetricRepository();

        // Create dependency graph with two extends relationships
        $graph = $this->graph([
            $this->createExtends('App\\ChildA', 'App\\BaseClass', 'child1.php', 20),
            $this->createExtends('App\\ChildB', 'App\\BaseClass', 'child2.php', 30),
        ]);

        // Add parent class
        $parentPath = SymbolPath::forClass('App', 'BaseClass');
        $repository->add($parentPath, new MetricBag(), RelativePath::fromString('base.php'), 10);

        // Add first child
        $child1Path = SymbolPath::forClass('App', 'ChildA');
        $repository->add($child1Path, new MetricBag(), RelativePath::fromString('child1.php'), 20);

        // Add second child
        $child2Path = SymbolPath::forClass('App', 'ChildB');
        $repository->add($child2Path, new MetricBag(), RelativePath::fromString('child2.php'), 30);

        $this->collector->calculate($graph, $repository);

        $parentMetrics = $repository->get($parentPath);
        self::assertSame(2, $parentMetrics->get('design.noc'));
    }

    #[Test]
    public function itDoesNotCountIndirectDescendantsTowardNoc(): void
    {
        // A extends B extends C
        // NOC(C) = 1 (only B), not 2 (B and A)
        $repository = new InMemoryMetricRepository();

        // Create dependency graph with two-level inheritance chain
        $graph = $this->graph([
            $this->createExtends('App\\Parent', 'App\\GrandParent', 'parent.php', 20),
            $this->createExtends('App\\Child', 'App\\Parent', 'child.php', 30),
        ]);

        // Add grandparent
        $grandparentPath = SymbolPath::forClass('App', 'GrandParent');
        $repository->add($grandparentPath, new MetricBag(), RelativePath::fromString('grand.php'), 10);

        // Add parent (child of grandparent)
        $parentPath = SymbolPath::forClass('App', 'Parent');
        $repository->add($parentPath, new MetricBag(), RelativePath::fromString('parent.php'), 20);

        // Add child (child of parent)
        $childPath = SymbolPath::forClass('App', 'Child');
        $repository->add($childPath, new MetricBag(), RelativePath::fromString('child.php'), 30);

        $this->collector->calculate($graph, $repository);

        // GrandParent has only 1 direct child (Parent)
        $grandparentMetrics = $repository->get($grandparentPath);
        self::assertSame(1, $grandparentMetrics->get('design.noc'));

        // Parent has only 1 direct child (Child)
        $parentMetricsAfter = $repository->get($parentPath);
        self::assertSame(1, $parentMetricsAfter->get('design.noc'));

        // Child has no children
        $childMetricsAfter = $repository->get($childPath);
        self::assertSame(0, $childMetricsAfter->get('design.noc'));
    }

    #[Test]
    public function itCountsChildrenDeclaredInOtherFiles(): void
    {
        // Parent in one namespace, children in another
        $repository = new InMemoryMetricRepository();

        // Create dependency graph with cross-namespace extends
        $graph = $this->graph([
            $this->createExtends('App\\ServiceA', 'Vendor\\BaseService', 'app/service-a.php', 20),
            $this->createExtends('App\\ServiceB', 'Vendor\\BaseService', 'app/service-b.php', 30),
        ]);

        // Add parent in Vendor namespace
        $parentPath = SymbolPath::forClass('Vendor', 'BaseService');
        $repository->add($parentPath, new MetricBag(), RelativePath::fromString('vendor/base.php'), 10);

        // Add children in App namespace
        $child1Path = SymbolPath::forClass('App', 'ServiceA');
        $repository->add($child1Path, new MetricBag(), RelativePath::fromString('app/service-a.php'), 20);

        $child2Path = SymbolPath::forClass('App', 'ServiceB');
        $repository->add($child2Path, new MetricBag(), RelativePath::fromString('app/service-b.php'), 30);

        $this->collector->calculate($graph, $repository);

        $parentMetrics = $repository->get($parentPath);
        self::assertSame(2, $parentMetrics->get('design.noc'));
    }

    #[Test]
    public function itCountsAChildExtendingAGlobalNamespaceParent(): void
    {
        // Parent in global namespace
        $repository = new InMemoryMetricRepository();

        // Create dependency graph with global namespace parent
        $graph = $this->graph([
            $this->createExtends('App\\Child', 'GlobalParent', 'child.php', 20),
        ]);

        // Add parent in global namespace
        $parentPath = SymbolPath::forClass('', 'GlobalParent');
        $repository->add($parentPath, new MetricBag(), RelativePath::fromString('global.php'), 10);

        // Add child extending global parent
        $childPath = SymbolPath::forClass('App', 'Child');
        $repository->add($childPath, new MetricBag(), RelativePath::fromString('child.php'), 20);

        $this->collector->calculate($graph, $repository);

        $parentMetrics = $repository->get($parentPath);
        self::assertSame(1, $parentMetrics->get('design.noc'));
    }

    #[Test]
    public function itDoesNotAssignNocToAParentOutsideTheRepository(): void
    {
        // Child in namespace extending parent not in repository (e.g. built-in Exception).
        // The parent should NOT get NOC metrics — only project classes get metrics.
        $repository = new InMemoryMetricRepository();

        $graph = $this->graph([
            $this->createExtends('App\\Service\\MyException', 'Exception', 'exception.php', 10),
        ]);

        // Only the project class is in the repository
        $childPath = SymbolPath::forClass('App\\Service', 'MyException');
        $repository->add($childPath, new MetricBag(), RelativePath::fromString('exception.php'), 10);

        $this->collector->calculate($graph, $repository);

        // Exception (parent) is NOT in the repository, so it should NOT get NOC metric
        $parentPath = SymbolPath::forClass('', 'Exception');
        self::assertFalse($repository->has($parentPath));

        // Child should have NOC = 0 (no children of its own)
        $childMetrics = $repository->get($childPath);
        self::assertSame(0, $childMetrics->get('design.noc'));
    }

    #[Test]
    public function itAssignsTheNocMetricToEveryClass(): void
    {
        // Ensure all classes get NOC metric, even if 0
        $repository = new InMemoryMetricRepository();
        $graph = $this->graph([]);

        // Add multiple classes
        $class1Path = SymbolPath::forClass('App', 'ClassA');
        $repository->add($class1Path, new MetricBag(), RelativePath::fromString('a.php'), 10);

        $class2Path = SymbolPath::forClass('App', 'ClassB');
        $repository->add($class2Path, new MetricBag(), RelativePath::fromString('b.php'), 20);

        $this->collector->calculate($graph, $repository);

        // All classes should have noc metric
        foreach ($repository->all(SymbolLevel::Class_) as $classInfo) {
            $metrics = $repository->get($classInfo->symbolPath);
            self::assertTrue($metrics->has('design.noc'));
        }
    }

    /**
     * An anonymous class's `extends` header has no declaration identity of
     * its own, so the edge is recorded with the enclosing class as source and
     * flagged `describesNestedAnonymousClass`. Counting it as the enclosing
     * class's own `extends` (the pre-cure defect) would give the parent an
     * extra child it never declared.
     */
    #[Test]
    public function itDoesNotCountAFlaggedExtendsEdgeAsAChild(): void
    {
        $repository = new InMemoryMetricRepository();

        // An\L1 extends An\L0 for real; An\Host's anonymous class extends
        // An\L1, flagged -- Host must not be counted as An\L1's child.
        $graph = $this->graph([
            $this->createExtends('An\\L1', 'An\\L0', 'l1.php', 1),
            $this->createExtends('An\\Host', 'An\\L1', 'host.php', 1, describesNestedAnonymousClass: true),
        ]);

        $l0Path = SymbolPath::forClass('An', 'L0');
        $repository->add($l0Path, new MetricBag(), RelativePath::fromString('l0.php'), 1);

        $l1Path = SymbolPath::forClass('An', 'L1');
        $repository->add($l1Path, new MetricBag(), RelativePath::fromString('l1.php'), 1);

        $hostPath = SymbolPath::forClass('An', 'Host');
        $repository->add($hostPath, new MetricBag(), RelativePath::fromString('host.php'), 1);

        $this->collector->calculate($graph, $repository);

        self::assertSame(1, $repository->get($l0Path)->get('design.noc'), 'L0 has one real child, L1');
        self::assertSame(0, $repository->get($l1Path)->get('design.noc'), 'The flagged edge must not count Host as a child of L1');
    }

    #[Test]
    public function itPreservesExistingMetricsWhenAddingNoc(): void
    {
        // NOC calculation should not overwrite existing metrics
        $repository = new InMemoryMetricRepository();

        // Create dependency graph
        $graph = $this->graph([
            $this->createExtends('App\\ChildClass', 'App\\BaseClass', 'child.php', 20),
        ]);

        // Add parent with existing metrics
        $parentPath = SymbolPath::forClass('App', 'BaseClass');
        $existingMetrics = (new MetricBag())->with('design.dit', 2)->with('complexity.wmc', 10);
        $repository->add($parentPath, $existingMetrics, RelativePath::fromString('base.php'), 10);

        // Add child
        $childPath = SymbolPath::forClass('App', 'ChildClass');
        $repository->add($childPath, new MetricBag(), RelativePath::fromString('child.php'), 20);

        $this->collector->calculate($graph, $repository);

        // Parent should still have original metrics + noc
        $parentMetrics = $repository->get($parentPath);
        self::assertSame(2, $parentMetrics->get('design.dit'));
        self::assertSame(10, $parentMetrics->get('complexity.wmc'));
        self::assertSame(1, $parentMetrics->get('design.noc'));
    }

    #[Test]
    public function itCountsASubclassDeclaredInTwoFilesOnce(): void
    {
        $repository = new InMemoryMetricRepository();

        // The `class_exists()`-guarded polyfill shape: one subclass name, two
        // files, and only one of them alive in any run.
        $graph = $this->graph([
            $this->createExtends('App\\Shim', 'App\\BaseClass', 'polyfill.php', 20),
            $this->createExtends('App\\Shim', 'App\\BaseClass', 'native.php', 30),
        ]);

        $parentPath = SymbolPath::forClass('App', 'BaseClass');
        $repository->add($parentPath, new MetricBag(), RelativePath::fromString('base.php'), 10);

        $childPath = SymbolPath::forClass('App', 'Shim');
        $repository->add($childPath, new MetricBag(), RelativePath::fromString('native.php'), 30);

        $this->collector->calculate($graph, $repository);

        self::assertSame(1, $repository->get($parentPath)->get('design.noc'));
    }

    /**
     * NOC stays a fact about a name, deliberately: `extends` records which name
     * a class inherits from, never which file declared it, so there is nothing
     * to attach a per-declaration count to.
     */
    #[Test]
    public function itReportsOneCountForAParentNameDeclaredInTwoFiles(): void
    {
        $repository = new InMemoryMetricRepository();

        $graph = $this->graph([
            $this->createExtends('App\\ChildA', 'App\\BaseClass', 'child-a.php', 20),
            $this->createExtends('App\\ChildB', 'App\\BaseClass', 'child-b.php', 30),
        ]);

        $parentPath = SymbolPath::forClass('App', 'BaseClass');
        $repository->addSubject(
            MetricSubject::declaration(DeclarationPath::of($parentPath, RelativePath::fromString('base-one.php'), DeclarationOrdinal::fromRank(0))),
            new MetricBag(),
            RelativePath::fromString('base-one.php'),
            10,
        );
        $repository->addSubject(
            MetricSubject::declaration(DeclarationPath::of($parentPath, RelativePath::fromString('base-two.php'), DeclarationOrdinal::fromRank(0))),
            new MetricBag(),
            RelativePath::fromString('base-two.php'),
            10,
        );

        $this->collector->calculate($graph, $repository);

        self::assertSame(2, $repository->get($parentPath)->get('design.noc'));
    }

    /** @param list<Dependency> $dependencies */
    private function graph(array $dependencies): DependencyGraphInterface
    {
        $universe = array_map(
            static fn(Dependency $dependency): LogicalClassPath => new LogicalClassPath($dependency->sourceLogical()),
            $dependencies,
        );

        return AdjacencyGraphBuilder::builder()->build($dependencies, $universe);
    }
}
