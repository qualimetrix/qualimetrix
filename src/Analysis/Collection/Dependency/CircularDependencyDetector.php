<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection\Dependency;

use AiMessDetector\Core\Dependency\DependencyGraphInterface;

/**
 * Detects circular dependencies using Tarjan's strongly connected components algorithm.
 *
 * Time complexity: O(V + E) where V is number of classes and E is number of dependencies.
 */
class CircularDependencyDetector
{
    private int $index = 0;

    /** @var array<string> */
    private array $stack = [];

    /** @var array<string, bool> */
    private array $onStack = [];

    /** @var array<string, int> */
    private array $indices = [];

    /** @var array<string, int> */
    private array $lowlinks = [];

    /** @var array<array<string>> */
    private array $sccs = [];

    /**
     * Detects all circular dependencies in the graph.
     *
     * @return list<Cycle> All detected cycles
     */
    public function detect(DependencyGraphInterface $graph): array
    {
        $this->reset();

        foreach ($graph->getAllClasses() as $class) {
            if (!isset($this->indices[$class])) {
                $this->strongConnect($class, $graph);
            }
        }

        // Filter SCCs with size > 1 (these are cycles)
        $cycles = [];
        foreach ($this->sccs as $scc) {
            if (\count($scc) > 1) {
                $cycles[] = new Cycle(array_values($scc), $this->findPath($scc, $graph));
            }
        }

        return $cycles;
    }

    /**
     * Resets detector state for a new analysis.
     */
    private function reset(): void
    {
        $this->index = 0;
        $this->stack = [];
        $this->onStack = [];
        $this->indices = [];
        $this->lowlinks = [];
        $this->sccs = [];
    }

    /**
     * Tarjan's algorithm: recursively visits nodes to find SCCs.
     */
    private function strongConnect(string $node, DependencyGraphInterface $graph): void
    {
        $this->indices[$node] = $this->index;
        $this->lowlinks[$node] = $this->index;
        $this->index++;
        $this->stack[] = $node;
        $this->onStack[$node] = true;

        // Visit all dependencies
        foreach ($graph->getClassDependencies($node) as $dependency) {
            $target = $dependency->targetClass;

            if (!isset($this->indices[$target])) {
                // Target not visited yet
                $this->strongConnect($target, $graph);
                $this->lowlinks[$node] = min(
                    $this->lowlinks[$node],
                    $this->lowlinks[$target],
                );
            } elseif ($this->onStack[$target] ?? false) {
                // Target is on stack (part of current SCC)
                $this->lowlinks[$node] = min(
                    $this->lowlinks[$node],
                    $this->indices[$target],
                );
            }
        }

        // If this is the root of an SCC, pop the SCC from stack
        if ($this->lowlinks[$node] === $this->indices[$node]) {
            $scc = [];
            do {
                $w = array_pop($this->stack);
                if ($w === null) {
                    break; // Safety check
                }
                $this->onStack[$w] = false;
                $scc[] = $w;
            } while ($w !== $node && $this->stack !== []);

            if ($scc !== []) {
                $this->sccs[] = $scc;
            }
        }
    }

    /**
     * Finds a concrete cycle path within an SCC for display purposes.
     *
     * Uses BFS to find the shortest path from the first class back to itself.
     *
     * @param array<string> $scc Classes in the strongly connected component
     *
     * @return list<string> Path forming a cycle (e.g., [A, B, C, A])
     */
    private function findPath(array $scc, DependencyGraphInterface $graph): array
    {
        $start = $scc[0];
        $sccSet = array_flip($scc);

        /** @var array<array<string>> $queue */
        $queue = [[$start]];
        $visited = [];

        while ($queue !== []) {
            $path = array_shift($queue);
            $current = end($path);
            if ($current === false) {
                continue; // Empty path, skip
            }

            foreach ($graph->getClassDependencies($current) as $dependency) {
                $target = $dependency->targetClass;

                if (!isset($sccSet[$target])) {
                    continue; // Not in this SCC
                }

                if ($target === $start && \count($path) > 1) {
                    // Found a cycle back to start
                    return array_values([...$path, $start]);
                }

                if (!isset($visited[$target])) {
                    $visited[$target] = true;
                    $queue[] = [...$path, $target];
                }
            }
        }

        // Fallback: return the SCC as-is with first element repeated
        return array_values([...$scc, $start]);
    }
}
