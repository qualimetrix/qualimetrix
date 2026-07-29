<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Structure;

/**
 * Builds the LCOM4 method-cohesion graph and counts its connected components.
 *
 * Encapsulates the two later phases of the LCOM4 algorithm — graph construction
 * (shared-property edges + method-call edges, with optional redirection of
 * stateless methods into a single virtual node) and component counting via BFS.
 * {@see LcomClassData} owns the first phase (classifying methods as stateful or
 * stateless) and delegates here for the graph work.
 */
final class LcomGraphCalculator
{
    private const string VIRTUAL_STATELESS_NODE = '__stateless__';

    /**
     * @param array<string, array<string, true>> $propertyAccesses Map of method => set of properties accessed
     * @param array<string, array<string, true>> $methodCalls Map of caller method => set of called methods
     */
    public function __construct(
        private readonly array $propertyAccesses,
        private readonly array $methodCalls,
    ) {}

    /**
     * Count connected components for the standard graph (no stateless merging).
     *
     * @param list<string> $methods
     */
    public function countComponents(array $methods): int
    {
        $identity = static fn(string $method): string => $method;
        $adjacency = $this->buildAdjacency($methods, $methods, $identity);

        return $this->countConnectedComponents($methods, $adjacency);
    }

    /**
     * Count connected components after merging stateless methods into one virtual node.
     *
     * @param list<string> $statefulMethods Methods with real state (not effectively stateless)
     * @param array<string, true> $statelessMethods Set of methods merged into the virtual node
     */
    public function countComponentsWithStatelessMerge(array $statefulMethods, array $statelessMethods): int
    {
        $mergedMethods = $statefulMethods;
        $mergedMethods[] = self::VIRTUAL_STATELESS_NODE;

        if (\count($mergedMethods) === 1) {
            return 1;
        }

        $resolve = static fn(string $method): string => isset($statelessMethods[$method])
            ? self::VIRTUAL_STATELESS_NODE
            : $method;

        $adjacency = $this->buildAdjacency($mergedMethods, $statefulMethods, $resolve);

        return $this->countConnectedComponents($mergedMethods, $adjacency);
    }

    /**
     * @param list<string> $vertices Final graph vertex set (after resolution)
     * @param list<string> $propertyPairCandidates Raw method names to pairwise-check for shared properties
     * @param callable(string): string $resolve Maps a raw method name to its graph vertex
     *
     * @return array<string, list<string>>
     */
    private function buildAdjacency(array $vertices, array $propertyPairCandidates, callable $resolve): array
    {
        $adjacency = array_fill_keys($vertices, []);

        $this->addPropertyEdges($propertyPairCandidates, $resolve, $adjacency);
        $this->addMethodCallEdges(array_flip($vertices), $resolve, $adjacency);

        return $adjacency;
    }

    /**
     * Add an edge between two vertices whenever their underlying raw methods share a property.
     *
     * @param list<string> $methods
     * @param callable(string): string $resolve
     * @param array<string, list<string>> $adjacency
     */
    private function addPropertyEdges(array $methods, callable $resolve, array &$adjacency): void
    {
        $count = \count($methods);
        for ($i = 0; $i < $count - 1; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                if (!$this->shareProperty($methods[$i], $methods[$j])) {
                    continue;
                }

                $v1 = $resolve($methods[$i]);
                $v2 = $resolve($methods[$j]);
                $adjacency[$v1][] = $v2;
                $adjacency[$v2][] = $v1;
            }
        }
    }

    /**
     * Add an edge between two vertices whenever one calls the other via `$this->`.
     *
     * @param array<string, int> $vertexSet
     * @param callable(string): string $resolve
     * @param array<string, list<string>> $adjacency
     */
    private function addMethodCallEdges(array $vertexSet, callable $resolve, array &$adjacency): void
    {
        foreach ($this->methodCalls as $caller => $callees) {
            $resolvedCaller = $resolve($caller);
            if (!isset($vertexSet[$resolvedCaller])) {
                continue;
            }

            foreach ($callees as $callee => $_) {
                $resolvedCallee = $resolve($callee);
                if (!isset($vertexSet[$resolvedCallee]) || $resolvedCaller === $resolvedCallee) {
                    continue;
                }

                $adjacency[$resolvedCaller][] = $resolvedCallee;
                $adjacency[$resolvedCallee][] = $resolvedCaller;
            }
        }
    }

    /**
     * Check if two methods share at least one property.
     */
    private function shareProperty(string $m1, string $m2): bool
    {
        $props1 = $this->propertyAccesses[$m1] ?? [];
        $props2 = $this->propertyAccesses[$m2] ?? [];

        foreach ($props1 as $prop => $_) {
            if (isset($props2[$prop])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count connected components using BFS.
     *
     * @param list<string> $vertices
     * @param array<string, list<string>> $adjacency
     */
    private function countConnectedComponents(array $vertices, array $adjacency): int
    {
        $visited = [];
        $components = 0;

        foreach ($vertices as $vertex) {
            if (isset($visited[$vertex])) {
                continue;
            }

            ++$components;
            $this->bfs($vertex, $adjacency, $visited);
        }

        return $components;
    }

    /**
     * BFS to mark all nodes in a connected component.
     *
     * @param array<string, list<string>> $adjacency
     * @param array<string, true> $visited
     */
    private function bfs(string $start, array $adjacency, array &$visited): void
    {
        $queue = [$start];
        $visited[$start] = true;

        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($adjacency[$current] as $neighbor) {
                if (!isset($visited[$neighbor])) {
                    $visited[$neighbor] = true;
                    $queue[] = $neighbor;
                }
            }
        }
    }
}
