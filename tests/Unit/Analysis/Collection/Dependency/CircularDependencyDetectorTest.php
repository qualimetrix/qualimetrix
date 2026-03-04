<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Collection\Dependency;

use AiMessDetector\Analysis\Collection\Dependency\CircularDependencyDetector;
use AiMessDetector\Analysis\Collection\Dependency\DependencyGraph;
use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Dependency\DependencyType;
use AiMessDetector\Core\Violation\Location;
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
        // A → B → A
        $graph = $this->buildGraph([
            'A' => ['B'],
            'B' => ['A'],
        ]);

        $cycles = $this->detector->detect($graph);

        $this->assertCount(1, $cycles);
        $this->assertSame(2, $cycles[0]->getSize());
        $this->assertContains('A', $cycles[0]->getClasses());
        $this->assertContains('B', $cycles[0]->getClasses());
    }

    public function testDetectsTransitiveCycle(): void
    {
        // A → B → C → A
        $graph = $this->buildGraph([
            'A' => ['B'],
            'B' => ['C'],
            'C' => ['A'],
        ]);

        $cycles = $this->detector->detect($graph);

        $this->assertCount(1, $cycles);
        $this->assertSame(3, $cycles[0]->getSize());
        $this->assertContains('A', $cycles[0]->getClasses());
        $this->assertContains('B', $cycles[0]->getClasses());
        $this->assertContains('C', $cycles[0]->getClasses());
    }

    public function testDetectsMultipleCycles(): void
    {
        // A → B → A  and  C → D → C
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
        // A → B → C (no cycle)
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
        // UserService → OrderService → UserService (cycle)
        // NotificationService → (no cycle)
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
        // A → B → C → A
        $graph = $this->buildGraph([
            'A' => ['B'],
            'B' => ['C'],
            'C' => ['A'],
        ]);

        $cycles = $this->detector->detect($graph);

        $this->assertCount(1, $cycles);
        $path = $cycles[0]->getPath();

        // Path should start and end with the same class
        $this->assertSame($path[0], $path[\count($path) - 1]);
        // Path should be at least 4 elements (A → B → C → A)
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
        // A → B (no cycle)  and  C → D (no cycle)
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
        $classes = [];

        foreach ($adjacencyList as $source => $targets) {
            $classes[$source] = true;
            foreach ($targets as $target) {
                $classes[$target] = true;

                $dep = new Dependency(
                    sourceClass: $source,
                    targetClass: $target,
                    type: DependencyType::TypeHint,
                    location: new Location('test.php', 1),
                );

                $dependencies[] = $dep;
                $bySource[$source][] = $dep;
                $byTarget[$target][] = $dep;
            }
        }

        return new DependencyGraph(
            dependencies: $dependencies,
            bySource: $bySource,
            byTarget: $byTarget,
            classes: array_keys($classes),
            namespaces: [],
            namespaceCe: [],
            namespaceCa: [],
        );
    }
}
