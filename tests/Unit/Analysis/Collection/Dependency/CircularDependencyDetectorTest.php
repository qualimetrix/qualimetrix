<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Collection\Dependency;

use AiMessDetector\Analysis\Collection\Dependency\CircularDependencyDetector;
use AiMessDetector\Analysis\Collection\Dependency\DependencyGraph;
use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Dependency\DependencyType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\SymbolPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CircularDependencyDetector::class)]
final class CircularDependencyDetectorTest extends TestCase
{
    private CircularDependencyDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new CircularDependencyDetector();
    }

    public function testDetectsDirectCycle(): void
    {
        // A -> B -> A
        $graph = $this->buildGraph([
            'A' => ['B'],
            'B' => ['A'],
        ]);

        $cycles = $this->detector->detect($graph);

        $this->assertCount(1, $cycles);
        $this->assertSame(2, $cycles[0]->getSize());
        $classStrings = array_map(fn(SymbolPath $p) => $p->toString(), $cycles[0]->getClasses());
        $this->assertContains('A', $classStrings);
        $this->assertContains('B', $classStrings);
    }

    public function testDetectsTransitiveCycle(): void
    {
        // A -> B -> C -> A
        $graph = $this->buildGraph([
            'A' => ['B'],
            'B' => ['C'],
            'C' => ['A'],
        ]);

        $cycles = $this->detector->detect($graph);

        $this->assertCount(1, $cycles);
        $this->assertSame(3, $cycles[0]->getSize());
        $classStrings = array_map(fn(SymbolPath $p) => $p->toString(), $cycles[0]->getClasses());
        $this->assertContains('A', $classStrings);
        $this->assertContains('B', $classStrings);
        $this->assertContains('C', $classStrings);
    }

    public function testDetectsMultipleCycles(): void
    {
        // A -> B -> A  and  C -> D -> C
        $graph = $this->buildGraph([
            'A' => ['B'],
            'B' => ['A'],
            'C' => ['D'],
            'D' => ['C'],
        ]);

        $cycles = $this->detector->detect($graph);

        $this->assertCount(2, $cycles);
    }

    public function testNoCyclesInDAG(): void
    {
        // A -> B -> C (no cycle)
        $graph = $this->buildGraph([
            'A' => ['B'],
            'B' => ['C'],
            'C' => [],
        ]);

        $cycles = $this->detector->detect($graph);

        $this->assertEmpty($cycles);
    }

    public function testHandlesComplexGraph(): void
    {
        // UserService -> OrderService -> UserService (cycle)
        // NotificationService -> (no cycle)
        $graph = $this->buildGraph([
            'UserService' => ['OrderService', 'NotificationService'],
            'OrderService' => ['UserService'],
            'NotificationService' => [],
        ]);

        $cycles = $this->detector->detect($graph);

        $this->assertCount(1, $cycles);
        $this->assertSame(2, $cycles[0]->getSize());
    }

    public function testFindsPathInCycle(): void
    {
        // A -> B -> C -> A
        $graph = $this->buildGraph([
            'A' => ['B'],
            'B' => ['C'],
            'C' => ['A'],
        ]);

        $cycles = $this->detector->detect($graph);

        $this->assertCount(1, $cycles);
        $path = $cycles[0]->getPath();

        // Path should start and end with the same class
        $this->assertSame($path[0]->toCanonical(), $path[\count($path) - 1]->toCanonical());
        // Path should be at least 4 elements (A -> B -> C -> A)
        $this->assertGreaterThanOrEqual(4, \count($path));
    }

    public function testEmptyGraph(): void
    {
        $graph = $this->buildGraph([]);

        $cycles = $this->detector->detect($graph);

        $this->assertEmpty($cycles);
    }

    public function testSingleNodeNoCycle(): void
    {
        // A (no dependencies)
        $graph = $this->buildGraph([
            'A' => [],
        ]);

        $cycles = $this->detector->detect($graph);

        $this->assertEmpty($cycles);
    }

    public function testDisconnectedComponents(): void
    {
        // A -> B (no cycle)  and  C -> D (no cycle)
        $graph = $this->buildGraph([
            'A' => ['B'],
            'B' => [],
            'C' => ['D'],
            'D' => [],
        ]);

        $cycles = $this->detector->detect($graph);

        $this->assertEmpty($cycles);
    }

    /**
     * Builds a dependency graph from an adjacency list.
     *
     * @param array<string, list<string>> $adjacencyList
     */
    private function buildGraph(array $adjacencyList): DependencyGraph
    {
        $dependencies = [];
        $bySource = [];
        $byTarget = [];
        /** @var array<string, SymbolPath> $classMap */
        $classMap = [];

        foreach ($adjacencyList as $source => $targets) {
            $sourcePath = SymbolPath::fromClassFqn($source);
            $sourceKey = $sourcePath->toCanonical();
            $classMap[$sourceKey] = $sourcePath;

            foreach ($targets as $target) {
                $targetPath = SymbolPath::fromClassFqn($target);
                $targetKey = $targetPath->toCanonical();
                $classMap[$targetKey] = $targetPath;

                $dep = new Dependency(
                    source: $sourcePath,
                    target: $targetPath,
                    type: DependencyType::TypeHint,
                    location: new Location('test.php', 1),
                );

                $dependencies[] = $dep;
                $bySource[$sourceKey][] = $dep;
                $byTarget[$targetKey][] = $dep;
            }
        }

        return new DependencyGraph(
            dependencies: $dependencies,
            bySource: $bySource,
            byTarget: $byTarget,
            classes: array_values($classMap),
            namespaces: [],
            namespaceCe: [],
            namespaceCa: [],
        );
    }
}
