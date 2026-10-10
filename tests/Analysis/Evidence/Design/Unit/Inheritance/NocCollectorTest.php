<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Unit\Inheritance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\NocCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\ClassType;
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

    /** @var list<ClassLikeDeclaration> */
    private array $facts = [];

    protected function setUp(): void
    {
        $this->collector = new NocCollector();
    }

    /**
     * Helper method to create an extends dependency.
     */
    private function createExtends(string $childClass, string $parentClass, string $file = 'test.php', int $line = 1, bool $describesNestedAnonymousClass = false, bool $interfaceExtends = false): Dependency
    {
        return Dependency::ofClassLike(
            source: DeclarationPath::of(SymbolPath::fromClassFqn($childClass), RelativePath::fromString($file), DeclarationOrdinal::fromRank(0)),
            target: new LogicalClassPath(SymbolPath::fromClassFqn($parentClass)),
            type: DependencyType::Extends,
            location: new Location(RelativePath::fromString($file), $line),
            describesNestedAnonymousClass: $describesNestedAnonymousClass,
            interfaceExtends: $interfaceExtends,
        );
    }

    private static function classMetricsSeed(): MetricBag
    {
        return new MetricBag();
    }

    /**
     * NOC is a class metric, measured on the population DIT is: an interface
     * extending an interface is not a subclass, and an interface, a trait or
     * an enum has no NOC at all -- not even 0, which would enter every NOC
     * aggregate's denominator beside DIT's smaller one.
     */
    #[Test]
    public function itMeasuresNocOnClassesOnly(): void
    {
        $repository = new InMemoryMetricRepository([
            new MetricDefinition('design.dit', SymbolLevel::Class_),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
            ...$this->collector->getMetricDefinitions(),
        ]);
        $graph = $this->graph([
            $this->createExtends('N\\B', 'N\\A', interfaceExtends: true),
            $this->createExtends('N\\D', 'N\\C'),
        ]);

        foreach (['N\\A', 'N\\B', 'N\\T', 'N\\E'] as $typeWithoutDit) {
            $this->addClass($repository, SymbolPath::fromClassFqn($typeWithoutDit), new MetricBag(), RelativePath::fromString('all.php'), 1);
        }
        foreach (['N\\C', 'N\\D'] as $class) {
            $this->addClass($repository, SymbolPath::fromClassFqn($class), self::classMetricsSeed(), RelativePath::fromString('all.php'), 1);
        }

        $this->collector->calculate(AdjacencyGraphBuilder::builder()->build(array_values($graph->getAllDependencies()), $this->facts)->graph, $repository);

        foreach (['N\\A', 'N\\B', 'N\\T', 'N\\E'] as $typeWithoutDit) {
            self::assertFalse($this->classMetrics($repository, SymbolPath::fromClassFqn($typeWithoutDit))->has('design.noc'), $typeWithoutDit);
        }
        self::assertSame(1, $this->classMetrics($repository, SymbolPath::fromClassFqn('N\\C'))->get('design.noc'));
        self::assertSame(0, $this->classMetrics($repository, SymbolPath::fromClassFqn('N\\D'))->get('design.noc'));
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
        $repository = new InMemoryMetricRepository([
            new MetricDefinition('design.dit', SymbolLevel::Class_),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
            ...$this->collector->getMetricDefinitions(),
        ]);
        $graph = $this->graph([]);

        // Add leaf class without parent
        $leafPath = SymbolPath::forClass('App', 'LeafClass');
        $this->addClass($repository, $leafPath, self::classMetricsSeed(), RelativePath::fromString('test.php'), 10);

        $this->collector->calculate(AdjacencyGraphBuilder::builder()->build(array_values($graph->getAllDependencies()), $this->facts)->graph, $repository);

        $metrics = $this->classMetrics($repository, $leafPath);
        self::assertSame(0, $metrics->get('design.noc'));
    }

    #[Test]
    public function itAssignsNocOneToAClassWithOneChild(): void
    {
        // Parent class with one child
        $repository = new InMemoryMetricRepository([
            new MetricDefinition('design.dit', SymbolLevel::Class_),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
            ...$this->collector->getMetricDefinitions(),
        ]);

        // Create dependency graph with extends relationship
        $extends = $this->createExtends('App\\ChildClass', 'App\\BaseClass', 'child.php', 20);
        $graph = $this->graph([$extends]);

        // Add parent class
        $parentPath = SymbolPath::forClass('App', 'BaseClass');
        $this->addClass($repository, $parentPath, self::classMetricsSeed(), RelativePath::fromString('base.php'), 10);

        // Add child class
        $childPath = SymbolPath::forClass('App', 'ChildClass');
        $this->addClass($repository, $childPath, self::classMetricsSeed(), RelativePath::fromString('child.php'), 20);

        $this->collector->calculate(AdjacencyGraphBuilder::builder()->build(array_values($graph->getAllDependencies()), $this->facts)->graph, $repository);

        $parentMetrics = $this->classMetrics($repository, $parentPath);
        self::assertSame(1, $parentMetrics->get('design.noc'));

        $childMetricsAfter = $this->classMetrics($repository, $childPath);
        self::assertSame(0, $childMetricsAfter->get('design.noc'));
    }

    #[Test]
    public function itAssignsNocTwoToAClassWithTwoChildren(): void
    {
        // Parent class with two direct children
        $repository = new InMemoryMetricRepository([
            new MetricDefinition('design.dit', SymbolLevel::Class_),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
            ...$this->collector->getMetricDefinitions(),
        ]);

        // Create dependency graph with two extends relationships
        $graph = $this->graph([
            $this->createExtends('App\\ChildA', 'App\\BaseClass', 'child1.php', 20),
            $this->createExtends('App\\ChildB', 'App\\BaseClass', 'child2.php', 30),
        ]);

        // Add parent class
        $parentPath = SymbolPath::forClass('App', 'BaseClass');
        $this->addClass($repository, $parentPath, self::classMetricsSeed(), RelativePath::fromString('base.php'), 10);

        // Add first child
        $child1Path = SymbolPath::forClass('App', 'ChildA');
        $this->addClass($repository, $child1Path, self::classMetricsSeed(), RelativePath::fromString('child1.php'), 20);

        // Add second child
        $child2Path = SymbolPath::forClass('App', 'ChildB');
        $this->addClass($repository, $child2Path, self::classMetricsSeed(), RelativePath::fromString('child2.php'), 30);

        $this->collector->calculate(AdjacencyGraphBuilder::builder()->build(array_values($graph->getAllDependencies()), $this->facts)->graph, $repository);

        $parentMetrics = $this->classMetrics($repository, $parentPath);
        self::assertSame(2, $parentMetrics->get('design.noc'));
    }

    #[Test]
    public function itDoesNotCountIndirectDescendantsTowardNoc(): void
    {
        // A extends B extends C
        // NOC(C) = 1 (only B), not 2 (B and A)
        $repository = new InMemoryMetricRepository([
            new MetricDefinition('design.dit', SymbolLevel::Class_),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
            ...$this->collector->getMetricDefinitions(),
        ]);

        // Create dependency graph with two-level inheritance chain
        $graph = $this->graph([
            $this->createExtends('App\\Parent', 'App\\GrandParent', 'parent.php', 20),
            $this->createExtends('App\\Child', 'App\\Parent', 'child.php', 30),
        ]);

        // Add grandparent
        $grandparentPath = SymbolPath::forClass('App', 'GrandParent');
        $this->addClass($repository, $grandparentPath, self::classMetricsSeed(), RelativePath::fromString('grand.php'), 10);

        // Add parent (child of grandparent)
        $parentPath = SymbolPath::forClass('App', 'Parent');
        $this->addClass($repository, $parentPath, self::classMetricsSeed(), RelativePath::fromString('parent.php'), 20);

        // Add child (child of parent)
        $childPath = SymbolPath::forClass('App', 'Child');
        $this->addClass($repository, $childPath, self::classMetricsSeed(), RelativePath::fromString('child.php'), 30);

        $this->collector->calculate(AdjacencyGraphBuilder::builder()->build(array_values($graph->getAllDependencies()), $this->facts)->graph, $repository);

        // GrandParent has only 1 direct child (Parent)
        $grandparentMetrics = $this->classMetrics($repository, $grandparentPath);
        self::assertSame(1, $grandparentMetrics->get('design.noc'));

        // Parent has only 1 direct child (Child)
        $parentMetricsAfter = $this->classMetrics($repository, $parentPath);
        self::assertSame(1, $parentMetricsAfter->get('design.noc'));

        // Child has no children
        $childMetricsAfter = $this->classMetrics($repository, $childPath);
        self::assertSame(0, $childMetricsAfter->get('design.noc'));
    }

    #[Test]
    public function itCountsChildrenDeclaredInOtherFiles(): void
    {
        // Parent in one namespace, children in another
        $repository = new InMemoryMetricRepository([
            new MetricDefinition('design.dit', SymbolLevel::Class_),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
            ...$this->collector->getMetricDefinitions(),
        ]);

        // Create dependency graph with cross-namespace extends
        $graph = $this->graph([
            $this->createExtends('App\\ServiceA', 'Vendor\\BaseService', 'app/service-a.php', 20),
            $this->createExtends('App\\ServiceB', 'Vendor\\BaseService', 'app/service-b.php', 30),
        ]);

        // Add parent in Vendor namespace
        $parentPath = SymbolPath::forClass('Vendor', 'BaseService');
        $this->addClass($repository, $parentPath, self::classMetricsSeed(), RelativePath::fromString('vendor/base.php'), 10);

        // Add children in App namespace
        $child1Path = SymbolPath::forClass('App', 'ServiceA');
        $this->addClass($repository, $child1Path, self::classMetricsSeed(), RelativePath::fromString('app/service-a.php'), 20);

        $child2Path = SymbolPath::forClass('App', 'ServiceB');
        $this->addClass($repository, $child2Path, self::classMetricsSeed(), RelativePath::fromString('app/service-b.php'), 30);

        $this->collector->calculate(AdjacencyGraphBuilder::builder()->build(array_values($graph->getAllDependencies()), $this->facts)->graph, $repository);

        $parentMetrics = $this->classMetrics($repository, $parentPath);
        self::assertSame(2, $parentMetrics->get('design.noc'));
    }

    #[Test]
    public function itCountsAChildExtendingAGlobalNamespaceParent(): void
    {
        // Parent in global namespace
        $repository = new InMemoryMetricRepository([
            new MetricDefinition('design.dit', SymbolLevel::Class_),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
            ...$this->collector->getMetricDefinitions(),
        ]);

        // Create dependency graph with global namespace parent
        $graph = $this->graph([
            $this->createExtends('App\\Child', 'GlobalParent', 'child.php', 20),
        ]);

        // Add parent in global namespace
        $parentPath = SymbolPath::forClass('', 'GlobalParent');
        $this->addClass($repository, $parentPath, self::classMetricsSeed(), RelativePath::fromString('global.php'), 10);

        // Add child extending global parent
        $childPath = SymbolPath::forClass('App', 'Child');
        $this->addClass($repository, $childPath, self::classMetricsSeed(), RelativePath::fromString('child.php'), 20);

        $this->collector->calculate(AdjacencyGraphBuilder::builder()->build(array_values($graph->getAllDependencies()), $this->facts)->graph, $repository);

        $parentMetrics = $this->classMetrics($repository, $parentPath);
        self::assertSame(1, $parentMetrics->get('design.noc'));
    }

    #[Test]
    public function itDoesNotAssignNocToAParentOutsideTheRepository(): void
    {
        // Child in namespace extending parent not in repository (e.g. built-in Exception).
        // The parent should NOT get NOC metrics — only project classes get metrics.
        $repository = new InMemoryMetricRepository([
            new MetricDefinition('design.dit', SymbolLevel::Class_),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
            ...$this->collector->getMetricDefinitions(),
        ]);

        $graph = $this->graph([
            $this->createExtends('App\\Service\\MyException', 'Exception', 'exception.php', 10),
        ]);

        // Only the project class is in the repository
        $childPath = SymbolPath::forClass('App\\Service', 'MyException');
        $this->addClass($repository, $childPath, self::classMetricsSeed(), RelativePath::fromString('exception.php'), 10);

        $this->collector->calculate(AdjacencyGraphBuilder::builder()->build(array_values($graph->getAllDependencies()), $this->facts)->graph, $repository);

        // Exception (parent) is NOT in the repository, so it should NOT get NOC metric
        $parentPath = SymbolPath::forClass('', 'Exception');
        self::assertFalse($repository->hasSubject(MetricSubject::logicalClass(new LogicalClassPath($parentPath))));

        // Child should have NOC = 0 (no children of its own)
        $childMetrics = $this->classMetrics($repository, $childPath);
        self::assertSame(0, $childMetrics->get('design.noc'));
    }

    #[Test]
    public function itAssignsTheNocMetricToEveryClass(): void
    {
        // Ensure all classes get NOC metric, even if 0
        $repository = new InMemoryMetricRepository([
            new MetricDefinition('design.dit', SymbolLevel::Class_),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
            ...$this->collector->getMetricDefinitions(),
        ]);
        $graph = $this->graph([]);

        // Add multiple classes
        $class1Path = SymbolPath::forClass('App', 'ClassA');
        $this->addClass($repository, $class1Path, self::classMetricsSeed(), RelativePath::fromString('a.php'), 10);

        $class2Path = SymbolPath::forClass('App', 'ClassB');
        $this->addClass($repository, $class2Path, self::classMetricsSeed(), RelativePath::fromString('b.php'), 20);

        $this->collector->calculate(AdjacencyGraphBuilder::builder()->build(array_values($graph->getAllDependencies()), $this->facts)->graph, $repository);

        // All classes should have noc metric
        foreach ($repository->allClassDeclarations() as $classInfo) {
            self::assertNotNull($classInfo->subject);
            $metrics = $repository->getSubject($classInfo->subject);
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
        $repository = new InMemoryMetricRepository([
            new MetricDefinition('design.dit', SymbolLevel::Class_),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
            ...$this->collector->getMetricDefinitions(),
        ]);

        // An\L1 extends An\L0 for real; An\Host's anonymous class extends
        // An\L1, flagged -- Host must not be counted as An\L1's child.
        $graph = $this->graph([
            $this->createExtends('An\\L1', 'An\\L0', 'l1.php', 1),
            $this->createExtends('An\\Host', 'An\\L1', 'host.php', 1, describesNestedAnonymousClass: true),
        ]);

        $l0Path = SymbolPath::forClass('An', 'L0');
        $this->addClass($repository, $l0Path, self::classMetricsSeed(), RelativePath::fromString('l0.php'), 1);

        $l1Path = SymbolPath::forClass('An', 'L1');
        $this->addClass($repository, $l1Path, self::classMetricsSeed(), RelativePath::fromString('l1.php'), 1);

        $hostPath = SymbolPath::forClass('An', 'Host');
        $this->addClass($repository, $hostPath, self::classMetricsSeed(), RelativePath::fromString('host.php'), 1);

        $this->collector->calculate(AdjacencyGraphBuilder::builder()->build(array_values($graph->getAllDependencies()), $this->facts)->graph, $repository);

        self::assertSame(1, $this->classMetrics($repository, $l0Path)->get('design.noc'), 'L0 has one real child, L1');
        self::assertSame(0, $this->classMetrics($repository, $l1Path)->get('design.noc'), 'The flagged edge must not count Host as a child of L1');
    }

    #[Test]
    public function itPreservesExistingMetricsWhenAddingNoc(): void
    {
        // NOC calculation should not overwrite existing metrics
        $repository = new InMemoryMetricRepository([
            new MetricDefinition('design.dit', SymbolLevel::Class_),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
            ...$this->collector->getMetricDefinitions(),
        ]);

        // Create dependency graph
        $graph = $this->graph([
            $this->createExtends('App\\ChildClass', 'App\\BaseClass', 'child.php', 20),
        ]);

        // Add parent with existing metrics
        $parentPath = SymbolPath::forClass('App', 'BaseClass');
        $existingMetrics = (new MetricBag())->with('design.dit', 2)->with('complexity.wmc', 10);
        $this->addClass($repository, $parentPath, $existingMetrics, RelativePath::fromString('base.php'), 10);

        // Add child
        $childPath = SymbolPath::forClass('App', 'ChildClass');
        $this->addClass($repository, $childPath, self::classMetricsSeed(), RelativePath::fromString('child.php'), 20);

        $this->collector->calculate(AdjacencyGraphBuilder::builder()->build(array_values($graph->getAllDependencies()), $this->facts)->graph, $repository);

        // Parent should still have original metrics + noc
        $parentMetrics = $this->classMetrics($repository, $parentPath);
        self::assertSame(2, $parentMetrics->get('design.dit'));
        self::assertSame(10, $parentMetrics->get('complexity.wmc'));
        self::assertSame(1, $parentMetrics->get('design.noc'));
    }

    #[Test]
    public function itCountsASubclassDeclaredInTwoFilesOnce(): void
    {
        $repository = new InMemoryMetricRepository([
            new MetricDefinition('design.dit', SymbolLevel::Class_),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
            ...$this->collector->getMetricDefinitions(),
        ]);

        // The `class_exists()`-guarded polyfill shape: one subclass name, two
        // files, and only one of them alive in any run.
        $graph = $this->graph([
            $this->createExtends('App\\Shim', 'App\\BaseClass', 'polyfill.php', 20),
            $this->createExtends('App\\Shim', 'App\\BaseClass', 'native.php', 30),
        ]);

        $parentPath = SymbolPath::forClass('App', 'BaseClass');
        $this->addClass($repository, $parentPath, self::classMetricsSeed(), RelativePath::fromString('base.php'), 10);

        $childPath = SymbolPath::forClass('App', 'Shim');
        $this->addClass($repository, $childPath, self::classMetricsSeed(), RelativePath::fromString('native.php'), 30);

        $this->collector->calculate(AdjacencyGraphBuilder::builder()->build(array_values($graph->getAllDependencies()), $this->facts)->graph, $repository);

        self::assertSame(1, $this->classMetrics($repository, $parentPath)->get('design.noc'));
    }

    /**
     * NOC stays a fact about a name, deliberately: `extends` records which name
     * a class inherits from, never which file declared it, so there is nothing
     * to attach a per-declaration count to.
     */
    #[Test]
    public function itReportsOneCountForAParentNameDeclaredInTwoFiles(): void
    {
        $repository = new InMemoryMetricRepository([
            new MetricDefinition('design.dit', SymbolLevel::Class_),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
            ...$this->collector->getMetricDefinitions(),
        ]);

        $graph = $this->graph([
            $this->createExtends('App\\ChildA', 'App\\BaseClass', 'child-a.php', 20),
            $this->createExtends('App\\ChildB', 'App\\BaseClass', 'child-b.php', 30),
        ]);

        $parentPath = SymbolPath::forClass('App', 'BaseClass');
        $first = MetricSubject::declaration(DeclarationPath::of($parentPath, RelativePath::fromString('base-one.php'), DeclarationOrdinal::fromRank(0)));
        $repository->addSubject(
            $first,
            self::classMetricsSeed(),
            RelativePath::fromString('base-one.php'),
            10,
        );
        $second = MetricSubject::declaration(DeclarationPath::of($parentPath, RelativePath::fromString('base-two.php'), DeclarationOrdinal::fromRank(0)));
        $repository->addSubject(
            $second,
            self::classMetricsSeed(),
            RelativePath::fromString('base-two.php'),
            10,
        );

        $this->collector->calculate(AdjacencyGraphBuilder::builder()->build(array_values($graph->getAllDependencies()), $this->facts)->graph, $repository);

        self::assertSame(2, $repository->getSubject($first)->get('design.noc'));
        self::assertSame(2, $repository->getSubject($second)->get('design.noc'));
    }

    /** @param list<Dependency> $dependencies */
    private function graph(array $dependencies): DependencyGraphInterface
    {
        $universe = array_map(
            static fn(Dependency $dependency): ClassLikeDeclaration => ClassLikeDeclaration::of(
                $dependency->source,
                ClassType::Class_,
                false,
                false,
            ),
            $dependencies,
        );

        return AdjacencyGraphBuilder::builder()->build($dependencies, $universe)->graph;
    }
    private function addClass(InMemoryMetricRepository $repository, SymbolPath $path, MetricBag $metrics, RelativePath $file, int $line): void
    {
        $declaration = DeclarationPath::of($path, $file, DeclarationOrdinal::fromRank(0));
        $kind = match ($path->toString()) {
            'N\\A', 'N\\B' => ClassType::Interface_,
            'N\\T' => ClassType::Trait_,
            'N\\E' => ClassType::Enum_,
            default => ClassType::Class_,
        };
        $this->facts[] = ClassLikeDeclaration::of($declaration, $kind, false, false);
        $repository->addSubject(MetricSubject::declaration($declaration), $metrics, $file, $line);
    }

    private function classMetrics(InMemoryMetricRepository $repository, SymbolPath $logical): MetricBag
    {
        $subjects = [];
        foreach ($repository->allClassDeclarations() as $info) {
            if ($info->symbolPath->toCanonical() === $logical->toCanonical()) {
                $subjects[] = $info->subject;
            }
        }
        self::assertCount(1, $subjects, 'Class fixture must identify exactly one declaration');
        self::assertNotNull($subjects[0]);

        return $repository->getSubject($subjects[0]);
    }

}
