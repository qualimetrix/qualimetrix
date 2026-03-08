<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Structure;

use AiMessDetector\Analysis\Collection\Dependency\DependencyGraphBuilder;
use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Dependency\DependencyType;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Metrics\Structure\NocCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
    private function createExtends(string $childClass, string $parentClass, string $file = '/test.php', int $line = 1): Dependency
    {
        return new Dependency(
            source: SymbolPath::fromClassFqn($childClass),
            target: SymbolPath::fromClassFqn($parentClass),
            type: DependencyType::Extends,
            location: new Location($file, $line),
        );
    }

    #[Test]
    public function getName_returnsNoc(): void
    {
        self::assertSame('noc', $this->collector->getName());
    }

    #[Test]
    public function requires_returnsEmpty(): void
    {
        self::assertSame([], $this->collector->requires());
    }

    #[Test]
    public function provides_returnsNoc(): void
    {
        self::assertSame(['noc'], $this->collector->provides());
    }

    #[Test]
    public function calculate_classWithoutChildren_hasNocZero(): void
    {
        // Leaf class with no children
        $repository = new InMemoryMetricRepository();
        $graph = (new DependencyGraphBuilder())->build([]);

        // Add leaf class without parent
        $leafPath = SymbolPath::forClass('App', 'LeafClass');
        $repository->add($leafPath, new MetricBag(), '/test.php', 10);

        $this->collector->calculate($graph, $repository);

        $metrics = $repository->get($leafPath);
        self::assertSame(0, $metrics->get('noc'));
    }

    #[Test]
    public function calculate_classWithOneChild_hasNocOne(): void
    {
        // Parent class with one child
        $repository = new InMemoryMetricRepository();

        // Create dependency graph with extends relationship
        $extends = new Dependency(
            source: SymbolPath::fromClassFqn('App\\ChildClass'),
            target: SymbolPath::fromClassFqn('App\\BaseClass'),
            type: DependencyType::Extends,
            location: new Location('/child.php', 20),
        );
        $graph = (new DependencyGraphBuilder())->build([$extends]);

        // Add parent class
        $parentPath = SymbolPath::forClass('App', 'BaseClass');
        $repository->add($parentPath, new MetricBag(), '/base.php', 10);

        // Add child class
        $childPath = SymbolPath::forClass('App', 'ChildClass');
        $repository->add($childPath, new MetricBag(), '/child.php', 20);

        $this->collector->calculate($graph, $repository);

        $parentMetrics = $repository->get($parentPath);
        self::assertSame(1, $parentMetrics->get('noc'));

        $childMetricsAfter = $repository->get($childPath);
        self::assertSame(0, $childMetricsAfter->get('noc'));
    }

    #[Test]
    public function calculate_classWithTwoChildren_hasNocTwo(): void
    {
        // Parent class with two direct children
        $repository = new InMemoryMetricRepository();

        // Create dependency graph with two extends relationships
        $graph = (new DependencyGraphBuilder())->build([
            $this->createExtends('App\\ChildA', 'App\\BaseClass', '/child1.php', 20),
            $this->createExtends('App\\ChildB', 'App\\BaseClass', '/child2.php', 30),
        ]);

        // Add parent class
        $parentPath = SymbolPath::forClass('App', 'BaseClass');
        $repository->add($parentPath, new MetricBag(), '/base.php', 10);

        // Add first child
        $child1Path = SymbolPath::forClass('App', 'ChildA');
        $repository->add($child1Path, new MetricBag(), '/child1.php', 20);

        // Add second child
        $child2Path = SymbolPath::forClass('App', 'ChildB');
        $repository->add($child2Path, new MetricBag(), '/child2.php', 30);

        $this->collector->calculate($graph, $repository);

        $parentMetrics = $repository->get($parentPath);
        self::assertSame(2, $parentMetrics->get('noc'));
    }

    #[Test]
    public function calculate_indirectChildren_notCounted(): void
    {
        // A extends B extends C
        // NOC(C) = 1 (only B), not 2 (B and A)
        $repository = new InMemoryMetricRepository();

        // Create dependency graph with two-level inheritance chain
        $graph = (new DependencyGraphBuilder())->build([
            $this->createExtends('App\\Parent', 'App\\GrandParent', '/parent.php', 20),
            $this->createExtends('App\\Child', 'App\\Parent', '/child.php', 30),
        ]);

        // Add grandparent
        $grandparentPath = SymbolPath::forClass('App', 'GrandParent');
        $repository->add($grandparentPath, new MetricBag(), '/grand.php', 10);

        // Add parent (child of grandparent)
        $parentPath = SymbolPath::forClass('App', 'Parent');
        $repository->add($parentPath, new MetricBag(), '/parent.php', 20);

        // Add child (child of parent)
        $childPath = SymbolPath::forClass('App', 'Child');
        $repository->add($childPath, new MetricBag(), '/child.php', 30);

        $this->collector->calculate($graph, $repository);

        // GrandParent has only 1 direct child (Parent)
        $grandparentMetrics = $repository->get($grandparentPath);
        self::assertSame(1, $grandparentMetrics->get('noc'));

        // Parent has only 1 direct child (Child)
        $parentMetricsAfter = $repository->get($parentPath);
        self::assertSame(1, $parentMetricsAfter->get('noc'));

        // Child has no children
        $childMetricsAfter = $repository->get($childPath);
        self::assertSame(0, $childMetricsAfter->get('noc'));
    }

    #[Test]
    public function calculate_crossFileInheritance_works(): void
    {
        // Parent in one namespace, children in another
        $repository = new InMemoryMetricRepository();

        // Create dependency graph with cross-namespace extends
        $graph = (new DependencyGraphBuilder())->build([
            $this->createExtends('App\\ServiceA', 'Vendor\\BaseService', '/app/service-a.php', 20),
            $this->createExtends('App\\ServiceB', 'Vendor\\BaseService', '/app/service-b.php', 30),
        ]);

        // Add parent in Vendor namespace
        $parentPath = SymbolPath::forClass('Vendor', 'BaseService');
        $repository->add($parentPath, new MetricBag(), '/vendor/base.php', 10);

        // Add children in App namespace
        $child1Path = SymbolPath::forClass('App', 'ServiceA');
        $repository->add($child1Path, new MetricBag(), '/app/service-a.php', 20);

        $child2Path = SymbolPath::forClass('App', 'ServiceB');
        $repository->add($child2Path, new MetricBag(), '/app/service-b.php', 30);

        $this->collector->calculate($graph, $repository);

        $parentMetrics = $repository->get($parentPath);
        self::assertSame(2, $parentMetrics->get('noc'));
    }

    #[Test]
    public function calculate_globalNamespaceClass_works(): void
    {
        // Parent in global namespace
        $repository = new InMemoryMetricRepository();

        // Create dependency graph with global namespace parent
        $graph = (new DependencyGraphBuilder())->build([
            $this->createExtends('App\\Child', 'GlobalParent', '/child.php', 20),
        ]);

        // Add parent in global namespace
        $parentPath = SymbolPath::forClass('', 'GlobalParent');
        $repository->add($parentPath, new MetricBag(), '/global.php', 10);

        // Add child extending global parent
        $childPath = SymbolPath::forClass('App', 'Child');
        $repository->add($childPath, new MetricBag(), '/child.php', 20);

        $this->collector->calculate($graph, $repository);

        $parentMetrics = $repository->get($parentPath);
        self::assertSame(1, $parentMetrics->get('noc'));
    }

    #[Test]
    public function calculate_childExtendingGlobalParent_skipsVendorParent(): void
    {
        // Child in namespace extending parent not in repository (e.g. built-in Exception).
        // The parent should NOT get NOC metrics — only project classes get metrics.
        $repository = new InMemoryMetricRepository();

        $graph = (new DependencyGraphBuilder())->build([
            $this->createExtends('App\\Service\\MyException', 'Exception', '/exception.php', 10),
        ]);

        // Only the project class is in the repository
        $childPath = SymbolPath::forClass('App\\Service', 'MyException');
        $repository->add($childPath, new MetricBag(), '/exception.php', 10);

        $this->collector->calculate($graph, $repository);

        // Exception (parent) is NOT in the repository, so it should NOT get NOC metric
        $parentPath = SymbolPath::forClass('', 'Exception');
        self::assertFalse($repository->has($parentPath));

        // Child should have NOC = 0 (no children of its own)
        $childMetrics = $repository->get($childPath);
        self::assertSame(0, $childMetrics->get('noc'));
    }

    #[Test]
    public function calculate_allClasses_haveNocMetric(): void
    {
        // Ensure all classes get NOC metric, even if 0
        $repository = new InMemoryMetricRepository();
        $graph = (new DependencyGraphBuilder())->build([]);

        // Add multiple classes
        $class1Path = SymbolPath::forClass('App', 'ClassA');
        $repository->add($class1Path, new MetricBag(), '/a.php', 10);

        $class2Path = SymbolPath::forClass('App', 'ClassB');
        $repository->add($class2Path, new MetricBag(), '/b.php', 20);

        $this->collector->calculate($graph, $repository);

        // All classes should have noc metric
        foreach ($repository->all(SymbolType::Class_) as $classInfo) {
            $metrics = $repository->get($classInfo->symbolPath);
            self::assertTrue($metrics->has('noc'));
        }
    }

    #[Test]
    public function calculate_preservesExistingMetrics(): void
    {
        // NOC calculation should not overwrite existing metrics
        $repository = new InMemoryMetricRepository();

        // Create dependency graph
        $graph = (new DependencyGraphBuilder())->build([
            $this->createExtends('App\\ChildClass', 'App\\BaseClass', '/child.php', 20),
        ]);

        // Add parent with existing metrics
        $parentPath = SymbolPath::forClass('App', 'BaseClass');
        $existingMetrics = (new MetricBag())->with('dit', 2)->with('wmc', 10);
        $repository->add($parentPath, $existingMetrics, '/base.php', 10);

        // Add child
        $childPath = SymbolPath::forClass('App', 'ChildClass');
        $repository->add($childPath, new MetricBag(), '/child.php', 20);

        $this->collector->calculate($graph, $repository);

        // Parent should still have original metrics + noc
        $parentMetrics = $repository->get($parentPath);
        self::assertSame(2, $parentMetrics->get('dit'));
        self::assertSame(10, $parentMetrics->get('wmc'));
        self::assertSame(1, $parentMetrics->get('noc'));
    }
}
