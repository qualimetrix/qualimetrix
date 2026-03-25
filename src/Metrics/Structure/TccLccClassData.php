<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Structure;

use SplQueue;

/**
 * Data structure for TCC/LCC calculation.
 *
 * Tracks public methods and their property accesses for a single class.
 * TCC (Tight Class Cohesion) measures direct connections via shared properties.
 * LCC (Loose Class Cohesion) includes transitive connections.
 */
final class TccLccClassData
{
    /**
     * Set of public method names in the class.
     *
     * @var array<string, true>
     */
    private array $methods = [];

    /**
     * Map of method => set of properties accessed.
     *
     * @var array<string, array<string, true>>
     */
    private array $propertyAccesses = [];

    /**
     * Number of declared non-static instance properties in the class.
     */
    private int $propertyCount = 0;

    public function __construct(
        public readonly ?string $namespace = null,
        public readonly string $className = '',
        public readonly int $line = 0,
    ) {}

    /**
     * Add a public method to the class.
     */
    public function addMethod(string $methodName): void
    {
        $this->methods[$methodName] = true;
    }

    /**
     * Increment the count of declared non-static instance properties.
     *
     * @param int $count number of properties in a single declaration (e.g. `public int $a, $b` = 2)
     */
    public function incrementPropertyCount(int $count = 1): void
    {
        $this->propertyCount += $count;
    }

    /**
     * Returns the number of declared non-static instance properties.
     */
    public function getPropertyCount(): int
    {
        return $this->propertyCount;
    }

    /**
     * Record that a method accesses a property.
     */
    public function addPropertyAccess(string $methodName, string $propertyName): void
    {
        if (!isset($this->propertyAccesses[$methodName])) {
            $this->propertyAccesses[$methodName] = [];
        }
        $this->propertyAccesses[$methodName][$propertyName] = true;
    }

    /**
     * @return list<string>
     */
    public function getMethods(): array
    {
        return array_keys($this->methods);
    }

    /**
     * @return list<string>
     */
    public function getPropertiesAccessedBy(string $methodName): array
    {
        return array_keys($this->propertyAccesses[$methodName] ?? []);
    }

    /**
     * Calculate TCC (Tight Class Cohesion).
     *
     * TCC = NDC / NP
     * Where:
     * - NDC = Number of Direct Connections (pairs of methods sharing properties)
     * - NP = Maximum Possible Pairs = N * (N - 1) / 2
     * - N = number of public methods
     *
     * Range: 0.0 (no cohesion) to 1.0 (perfect cohesion)
     */
    public function calculateTcc(): float
    {
        $methods = $this->getMethods();
        $n = \count($methods);

        if ($n < 2) {
            return 1.0; // Single method or no methods = perfect cohesion
        }

        $np = $n * ($n - 1) / 2; // Maximum possible pairs
        $ndc = 0; // Direct connections

        // Check each pair of methods for shared properties
        for ($i = 0; $i < $n - 1; ++$i) {
            for ($j = $i + 1; $j < $n; ++$j) {
                if ($this->shareProperty($methods[$i], $methods[$j])) {
                    ++$ndc;
                }
            }
        }

        return $ndc / $np;
    }

    /**
     * Calculate LCC (Loose Class Cohesion).
     *
     * LCC includes both direct and transitive connections.
     * Uses BFS to find all connected pairs in the method graph.
     *
     * LCC = NIC / NP
     * Where:
     * - NIC = Number of Indirect Connections (all connected pairs)
     * - NP = Maximum Possible Pairs = N * (N - 1) / 2
     *
     * Range: 0.0 (no cohesion) to 1.0 (perfect cohesion)
     */
    public function calculateLcc(): float
    {
        $methods = $this->getMethods();
        $n = \count($methods);

        if ($n < 2) {
            return 1.0;
        }

        $np = $n * ($n - 1) / 2;

        // Build graph: methods as nodes, edges if they share property
        $graph = $this->buildMethodGraph($methods);

        // Find transitive closure using BFS from each node
        $nic = $this->countConnectedPairsBfs($graph, $methods);

        return $nic / $np;
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
     * Build adjacency list for method graph.
     *
     * @param list<string> $methods
     *
     * @return array<string, list<string>>
     */
    private function buildMethodGraph(array $methods): array
    {
        $graph = [];
        $n = \count($methods);

        foreach ($methods as $method) {
            $graph[$method] = [];
        }

        // Add edges for methods that share properties
        for ($i = 0; $i < $n - 1; ++$i) {
            for ($j = $i + 1; $j < $n; ++$j) {
                if ($this->shareProperty($methods[$i], $methods[$j])) {
                    $graph[$methods[$i]][] = $methods[$j];
                    $graph[$methods[$j]][] = $methods[$i];
                }
            }
        }

        return $graph;
    }

    /**
     * Count connected pairs using BFS.
     *
     * For each method, perform BFS to find all reachable methods.
     * Count each pair only once by checking index ordering.
     *
     * Complexity: O(n * (n + m)) where m is number of edges.
     * More efficient than Floyd-Warshall O(n³) for sparse graphs.
     *
     * @param array<string, list<string>> $graph
     * @param list<string> $methods
     */
    private function countConnectedPairsBfs(array $graph, array $methods): int
    {
        $n = \count($methods);
        $methodIndex = array_flip($methods);
        $connectedPairs = 0;

        // For each method, find all reachable methods via BFS
        foreach ($methods as $i => $startMethod) {
            /** @var array<string, true> $visited */
            $visited = [$startMethod => true];
            $queue = new SplQueue();
            $queue->enqueue($startMethod);

            while (!$queue->isEmpty()) {
                $current = $queue->dequeue();

                foreach ($graph[$current] ?? [] as $neighbor) {
                    if (!isset($visited[$neighbor])) {
                        $visited[$neighbor] = true;
                        $queue->enqueue($neighbor);

                        // Count pair only if neighbor > startMethod (avoid counting twice)
                        $neighborIndex = $methodIndex[$neighbor];
                        if ($neighborIndex > $i) {
                            ++$connectedPairs;
                        }
                    }
                }
            }
        }

        return $connectedPairs;
    }
}
