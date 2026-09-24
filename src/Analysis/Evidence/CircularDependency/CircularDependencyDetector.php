<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CircularDependency;

use LogicException;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Detects circular dependencies using Tarjan's strongly connected components algorithm.
 *
 * Time complexity: O(V + E) where V is number of classes and E is number of dependencies.
 *
 * ## Canonical ordering
 *
 * Tarjan's algorithm yields a unique SCC *partition*, but the order of members
 * within an SCC — and the order of the SCCs themselves — depends on the graph
 * traversal order, which in turn depends on file discovery order. Since the
 * first class of a cycle becomes the finding's symbol path (and therefore its
 * baseline hash), that ordering is normalised here:
 *
 * - members of each SCC are sorted by their canonical symbol key, so the first
 *   member is the lexicographically smallest one (the cycle *representative*);
 * - {@see self::findPath()} starts from that representative and explores
 *   neighbours in canonical order, so the displayed path is stable too;
 * - the returned cycles are sorted by representative.
 *
 * The result is a pure function of the graph structure: adding an unrelated
 * file cannot change the identity of an existing cycle.
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

    /** @var list<non-empty-list<string>> */
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
        /** @var array<string, Cycle> $cyclesByRepresentative */
        $cyclesByRepresentative = [];
        foreach ($this->sccs as $scc) {
            if (\count($scc) > 1) {
                // Canonical member order: the smallest key becomes the cycle
                // representative, making the cycle identity traversal-independent.
                sort($scc, \SORT_STRING);
                $representative = $scc[0];

                $sccPaths = array_map(fn(string $key): SymbolPath => $this->symbolPathMap[$key], $scc);
                $pathPaths = array_map(
                    fn(string $key): SymbolPath => $this->symbolPathMap[$key],
                    $this->findPath($scc, $graph),
                );
                $cyclesByRepresentative[$representative] = new Cycle(
                    array_values($sccPaths),
                    array_values($pathPaths),
                );
            }
        }

        // SCCs are disjoint, so representatives are distinct and the order is total.
        ksort($cyclesByRepresentative, \SORT_STRING);

        return array_values($cyclesByRepresentative);
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
     * Tarjan's algorithm over an explicit stack of frames rather than the call
     * stack: the depth of the walk is the length of the longest dependency
     * chain, and recursing that deep hit Xdebug's nesting limit on a chain of a
     * few hundred classes. Each frame is a node, its successors, and the index
     * of the next successor to visit; popping a frame is returning from the
     * recursive call, so the partition and every SCC come out as before.
     */
    private function strongConnect(string $rootKey, DependencyGraphInterface $graph): void
    {
        $this->discover($rootKey);
        /** @var list<array{string, list<string>, int}> $frames */
        $frames = [[$rootKey, $this->successors($rootKey, $graph), 0]];

        while ($frames !== []) {
            $top = \count($frames) - 1;
            [$nodeKey, $successors, $next] = $frames[$top];

            if ($next < \count($successors)) {
                $frames[$top][2] = $next + 1;
                $targetKey = $successors[$next];

                if (!isset($this->indices[$targetKey])) {
                    $this->discover($targetKey);
                    $frames[] = [$targetKey, $this->successors($targetKey, $graph), 0];
                } elseif ($this->onStack[$targetKey] ?? false) {
                    $this->lowlinks[$nodeKey] = min($this->lowlinks[$nodeKey], $this->indices[$targetKey]);
                }

                continue;
            }

            array_pop($frames);
            $this->collectComponentRootedAt($nodeKey);

            if ($frames !== []) {
                $parentKey = $frames[\count($frames) - 1][0];
                $this->lowlinks[$parentKey] = min($this->lowlinks[$parentKey], $this->lowlinks[$nodeKey]);
            }
        }
    }

    private function discover(string $nodeKey): void
    {
        $this->indices[$nodeKey] = $this->index;
        $this->lowlinks[$nodeKey] = $this->index;
        $this->index++;
        $this->stack[] = $nodeKey;
        $this->onStack[$nodeKey] = true;
    }

    /**
     * The node's dependency targets in edge order, one per edge.
     *
     * @return list<string>
     */
    private function successors(string $nodeKey, DependencyGraphInterface $graph): array
    {
        return array_map(
            static fn(Dependency $dependency): string => $dependency->targetLogical()->toCanonical(),
            array_values($graph->getClassDependencies($this->symbolPathMap[$nodeKey])),
        );
    }

    /**
     * Pops the SCC off the stack when the node is its root.
     */
    private function collectComponentRootedAt(string $nodeKey): void
    {
        if ($this->lowlinks[$nodeKey] !== $this->indices[$nodeKey]) {
            return;
        }

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

    /**
     * Finds a concrete cycle path within an SCC for display purposes.
     *
     * Uses BFS to find the shortest path from the SCC representative back to
     * itself. Neighbours are visited in canonical order, so the resulting path
     * depends only on the graph structure — not on the order in which nodes and
     * edges were added.
     *
     * The result is the shortest cycle *through the representative*, which is
     * generally a subset of the SCC: a chain member that only lies on a longer
     * route back does not appear. The full member list is {@see Cycle::getClasses()}.
     *
     * @param non-empty-list<string> $scc Canonical keys of the SCC members, sorted ascending
     *
     * @return list<string> Path forming a cycle as canonical keys (e.g., [A, B, C, A])
     */
    private function findPath(array $scc, DependencyGraphInterface $graph): array
    {
        $start = $scc[0];
        $sccSet = array_flip($scc);

        // A queue read through a head index, with each node's predecessor kept
        // instead of its whole path: shifting the queue and copying a path per
        // edge made the search quadratic on a long cycle.
        $queue = [$start];
        $head = 0;
        /** @var array<string, string> $predecessor */
        $predecessor = [];
        // Seeding the start node keeps a self-loop on the representative from
        // being enqueued as a second hop, which would yield the degenerate
        // path A → A → A instead of a genuine walk through the cycle.
        $visited = [$start => true];

        while (isset($queue[$head])) {
            $current = $queue[$head++];

            foreach ($this->sortedNeighbours($current, $sccSet, $graph) as $targetKey) {
                if ($targetKey === $start && $current !== $start) {
                    return [...$this->pathTo($current, $predecessor), $start];
                }

                if (!isset($visited[$targetKey])) {
                    $visited[$targetKey] = true;
                    $predecessor[$targetKey] = $current;
                    $queue[] = $targetKey;
                }
            }
        }

        // Unreachable: within an SCC of size > 1 every member is reachable from
        // the representative and has a route back, and the return-to-start check
        // runs before the visited check, so the loop above always returns. The
        // previous fallback returned the member list itself, which is not a walk
        // — consecutive entries need not share an edge — and would quietly break
        // the path contract this class now advertises.
        throw new LogicException(\sprintf(
            'CircularDependencyDetector invariant violated: no cycle path found within the SCC [%s]',
            implode(', ', $scc),
        ));
    }

    /**
     * The walk from the search's start to `$nodeKey`, rebuilt from predecessors.
     *
     * @param array<string, string> $predecessor
     *
     * @return list<string>
     */
    private function pathTo(string $nodeKey, array $predecessor): array
    {
        $path = [$nodeKey];

        while (isset($predecessor[$nodeKey])) {
            $nodeKey = $predecessor[$nodeKey];
            $path[] = $nodeKey;
        }

        return array_reverse($path);
    }

    /**
     * Returns the SCC-internal successors of a node, deduplicated and sorted.
     *
     * The graph stores one dependency per use site, so the same target can occur
     * several times with different types and locations; only the distinct target
     * set matters for path finding.
     *
     * @param array<string, int> $sccSet Canonical keys of the SCC members, as a lookup set
     *
     * @return list<string>
     */
    private function sortedNeighbours(string $nodeKey, array $sccSet, DependencyGraphInterface $graph): array
    {
        $targets = [];
        foreach ($graph->getClassDependencies($this->symbolPathMap[$nodeKey]) as $dependency) {
            $targetKey = $dependency->targetLogical()->toCanonical();

            if (isset($sccSet[$targetKey])) {
                $targets[$targetKey] = true;
            }
        }

        $targetKeys = array_keys($targets);
        sort($targetKeys, \SORT_STRING);

        return $targetKeys;
    }
}
