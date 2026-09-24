<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyDetector;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\DepthRecordingGraph;

#[CoversClass(CircularDependencyDetector::class)]
final class CircularDependencyDetectorTest extends TestCase
{
    private CircularDependencyDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new CircularDependencyDetector();
    }

    #[Test]
    public function itDetectsDirectCycle(): void
    {
        // A -> B -> A
        $graph = $this->buildGraph([
            'A' => ['B'],
            'B' => ['A'],
        ]);

        $cycles = $this->detector->detect($graph);

        self::assertCount(1, $cycles);
        self::assertSame(2, $cycles[0]->getSize());
        $classStrings = array_map(fn(SymbolPath $p) => $p->toString(), $cycles[0]->getClasses());
        self::assertContains('A', $classStrings);
        self::assertContains('B', $classStrings);
    }

    #[Test]
    public function itDetectsTransitiveCycle(): void
    {
        // A -> B -> C -> A
        $graph = $this->buildGraph([
            'A' => ['B'],
            'B' => ['C'],
            'C' => ['A'],
        ]);

        $cycles = $this->detector->detect($graph);

        self::assertCount(1, $cycles);
        self::assertSame(3, $cycles[0]->getSize());
        $classStrings = array_map(fn(SymbolPath $p) => $p->toString(), $cycles[0]->getClasses());
        self::assertContains('A', $classStrings);
        self::assertContains('B', $classStrings);
        self::assertContains('C', $classStrings);
    }

    #[Test]
    public function itDetectsMultipleCycles(): void
    {
        // A -> B -> A  and  C -> D -> C
        $graph = $this->buildGraph([
            'A' => ['B'],
            'B' => ['A'],
            'C' => ['D'],
            'D' => ['C'],
        ]);

        $cycles = $this->detector->detect($graph);

        self::assertCount(2, $cycles);
    }

    #[Test]
    public function itHasNoCyclesInDAG(): void
    {
        // A -> B -> C (no cycle)
        $graph = $this->buildGraph([
            'A' => ['B'],
            'B' => ['C'],
            'C' => [],
        ]);

        $cycles = $this->detector->detect($graph);

        self::assertEmpty($cycles);
    }

    #[Test]
    public function itHandlesComplexGraph(): void
    {
        // UserService -> OrderService -> UserService (cycle)
        // NotificationService -> (no cycle)
        $graph = $this->buildGraph([
            'UserService' => ['OrderService', 'NotificationService'],
            'OrderService' => ['UserService'],
            'NotificationService' => [],
        ]);

        $cycles = $this->detector->detect($graph);

        self::assertCount(1, $cycles);
        self::assertSame(2, $cycles[0]->getSize());
    }

    #[Test]
    public function itFindsPathInCycle(): void
    {
        // A -> B -> C -> A
        $graph = $this->buildGraph([
            'A' => ['B'],
            'B' => ['C'],
            'C' => ['A'],
        ]);

        $cycles = $this->detector->detect($graph);

        self::assertCount(1, $cycles);
        $path = $cycles[0]->getPath();

        // Path should start and end with the same class
        self::assertSame($path[0]->toCanonical(), $path[\count($path) - 1]->toCanonical());
        // Path should be at least 4 elements (A -> B -> C -> A)
        self::assertGreaterThanOrEqual(4, \count($path));
    }

    #[Test]
    public function itHandlesEmptyGraph(): void
    {
        $graph = $this->buildGraph([]);

        $cycles = $this->detector->detect($graph);

        self::assertEmpty($cycles);
    }

    #[Test]
    public function itHasNoCycleForSingleNode(): void
    {
        // A (no dependencies)
        $graph = $this->buildGraph([
            'A' => [],
        ]);

        $cycles = $this->detector->detect($graph);

        self::assertEmpty($cycles);
    }

    #[Test]
    public function itHandlesDisconnectedComponents(): void
    {
        // A -> B (no cycle)  and  C -> D (no cycle)
        $graph = $this->buildGraph([
            'A' => ['B'],
            'B' => [],
            'C' => ['D'],
            'D' => [],
        ]);

        $cycles = $this->detector->detect($graph);

        self::assertEmpty($cycles);
    }

    /**
     * The walk must not grow the call stack with the length of a dependency
     * chain: recursive, a chain of a few hundred classes reached Xdebug's
     * default nesting limit of 256 and killed the run mid-phase. The stack
     * depth is read where the detector reads the graph, at every node.
     */
    #[Test]
    public function itWalksALongChainWithoutGrowingTheCallStack(): void
    {
        $length = 600;
        $adjacency = [];
        for ($i = 0; $i < $length; $i++) {
            $adjacency['Chain\\N' . $i] = $i + 1 < $length ? ['Chain\\N' . ($i + 1)] : ['Chain\\N0'];
        }
        $graph = new DepthRecordingGraph($this->buildGraph($adjacency));

        $cycles = $this->detector->detect($graph);

        self::assertCount(1, $cycles);
        self::assertCount($length, $cycles[0]->getClasses());
        self::assertCount($length + 1, $cycles[0]->getPath());
        self::assertLessThan(64, $graph->deepestStack, 'the call stack grew with the chain');
    }

    /**
     * The breadth-first path search and the iterative walk must still yield the
     * shortest cycle through the representative, taking the first branch in
     * canonical order when two are equally short.
     */
    #[Test]
    public function itFindsTheShortestCanonicalCycleThroughTheRepresentative(): void
    {
        $graph = $this->buildGraph([
            'A' => ['D', 'C', 'B'],
            'B' => ['A'],
            'C' => ['A'],
            'D' => ['E'],
            'E' => ['A'],
        ]);

        $cycles = $this->detector->detect($graph);

        self::assertCount(1, $cycles);
        self::assertSame(
            ['A', 'B', 'A'],
            array_map(static fn(SymbolPath $path): string => $path->toString(), $cycles[0]->getPath()),
        );
        self::assertSame(
            ['A', 'B', 'C', 'D', 'E'],
            array_map(static fn(SymbolPath $path): string => $path->toString(), $cycles[0]->getClasses()),
        );
    }

    /**
     * Builds a dependency graph from an adjacency list.
     *
     * @param array<string, list<string>> $adjacencyList
     */
    private function buildGraph(array $adjacencyList): DependencyGraphInterface
    {
        return AdjacencyGraphBuilder::build($adjacencyList);
    }
}
