<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Structure;

/**
 * Data structure for LCOM calculation.
 *
 * Tracks methods and their property accesses for a single class.
 */
final class LcomClassData
{
    /**
     * Set of method names in the class.
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

    public function __construct(
        public readonly ?string $namespace = null,
        public readonly string $className = '',
        public readonly int $line = 0,
    ) {}

    public function addMethod(string $methodName): void
    {
        $this->methods[$methodName] = true;
    }

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
     * Calculate LCOM4 (Lack of Cohesion of Methods).
     *
     * LCOM4 is the number of connected components in the graph where:
     * - Vertices = methods
     * - Edges = (m1, m2) if m1 and m2 share at least one property
     *
     * @return int Number of connected components (1 = perfectly cohesive)
     */
    public function calculateLcom(): int
    {
        $methods = $this->getMethods();
        $count = \count($methods);

        if ($count === 0) {
            return 0;
        }

        if ($count === 1) {
            return 1;
        }

        // Build adjacency list
        $adjacency = [];
        foreach ($methods as $method) {
            $adjacency[$method] = [];
        }

        // Add edges: two methods are connected if they share a property
        for ($i = 0; $i < $count - 1; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                $m1 = $methods[$i];
                $m2 = $methods[$j];

                if ($this->shareProperty($m1, $m2)) {
                    $adjacency[$m1][] = $m2;
                    $adjacency[$m2][] = $m1;
                }
            }
        }

        // Count connected components using BFS
        $visited = [];
        $components = 0;

        foreach ($methods as $method) {
            if (isset($visited[$method])) {
                continue;
            }

            // Start new component
            ++$components;
            $this->bfs($method, $adjacency, $visited);
        }

        return $components;
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
