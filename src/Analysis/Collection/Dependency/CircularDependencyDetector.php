<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection\Dependency;

use AiMessDetector\Core\Dependency\DependencyGraphInterface;
use AiMessDetector\Core\Violation\SymbolPath;

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

    /** @var array<string, SymbolPath> */
    private array $symbolPathMap = [];

    /**
     * Detects all circular dependencies in the graph.
     *
     * @return list<Cycle> All detected cycles
     */
    public function detect(DependencyGraphInterface $graph): array
    {
        $this->reset();

        // Build a map of canonical key → SymbolPath for reverse lookup
        foreach ($graph->getAllClasses() as $classPath) {
            $key = $classPath->toCanonical();
            $this->symbolPathMap[$key] = $classPath;
        }

        foreach ($graph->getAllClasses() as $classPath) {
            $key = $classPath->toCanonical();
            if (!isset($this->indices[$key])) {
                $this->strongConnect($key, $graph);
            }
        }

        // Filter SCCs with size > 1 (these are cycles)
        $cycles = [];
        foreach ($this->sccs as $scc) {
            if (\count($scc) > 1) {
                $sccPaths = array_map(fn(string $key): SymbolPath => $this->symbolPathMap[$key], $scc);
                $pathPaths = array_map(
                    fn(string $key): SymbolPath => $this->symbolPathMap[$key],
                    $this->findPath($scc, $graph),
                );
                $cycles[] = new Cycle(array_values($sccPaths), array_values($pathPaths));
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
        $this->symbolPathMap = [];
    }

    /**
     * Tarjan's algorithm: recursively visits nodes to find SCCs.
     */
    private function strongConnect(string $nodeKey, DependencyGraphInterface $graph): void
    {
        $this->indices[$nodeKey] = $this->index;
        $this->lowlinks[$nodeKey] = $this->index;
        $this->index++;
        $this->stack[] = $nodeKey;
        $this->onStack[$nodeKey] = true;

        // Visit all dependencies
        $nodePath = $this->symbolPathMap[$nodeKey];
        foreach ($graph->getClassDependencies($nodePath) as $dependency) {
            $targetKey = $dependency->target->toCanonical();

            if (!isset($this->indices[$targetKey])) {
                // Target not visited yet
                $this->strongConnect($targetKey, $graph);
                $this->lowlinks[$nodeKey] = min(
                    $this->lowlinks[$nodeKey],
                    $this->lowlinks[$targetKey],
                );
            } elseif ($this->onStack[$targetKey] ?? false) {
                // Target is on stack (part of current SCC)
                $this->lowlinks[$nodeKey] = min(
                    $this->lowlinks[$nodeKey],
                    $this->indices[$targetKey],
                );
            }
        }

        // If this is the root of an SCC, pop the SCC from stack
        if ($this->lowlinks[$nodeKey] === $this->indices[$nodeKey]) {
            $scc = [];
            do {
                $w = array_pop($this->stack);
                if ($w === null) {
                    break; // Safety check
                }
                $this->onStack[$w] = false;
                $scc[] = $w;
            } while ($w !== $nodeKey && $this->stack !== []);

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
     * @param array<string> $scc Canonical keys of classes in the strongly connected component
     *
     * @return list<string> Path forming a cycle as canonical keys (e.g., [A, B, C, A])
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

            $currentPath = $this->symbolPathMap[$current];
            foreach ($graph->getClassDependencies($currentPath) as $dependency) {
                $targetKey = $dependency->target->toCanonical();

                if (!isset($sccSet[$targetKey])) {
                    continue; // Not in this SCC
                }

                if ($targetKey === $start && \count($path) > 1) {
                    // Found a cycle back to start
                    return array_values([...$path, $start]);
                }

                if (!isset($visited[$targetKey])) {
                    $visited[$targetKey] = true;
                    $queue[] = [...$path, $targetKey];
                }
            }
        }

        // Fallback: return the SCC as-is with first element repeated
        return array_values([...$scc, $start]);
    }
}
