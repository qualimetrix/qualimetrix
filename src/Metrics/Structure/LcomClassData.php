<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Structure;

/**
 * Data structure for LCOM calculation.
 *
 * Tracks methods, their property accesses and method calls for a single class.
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

    /**
     * Map of method => set of called methods (via $this->method()).
     *
     * @var array<string, array<string, true>>
     */
    private array $methodCalls = [];

    /**
     * Set of static methods (excluded from LCOM graph).
     *
     * @var array<string, true>
     */
    private array $staticMethods = [];

    /**
     * Whether any non-trivial method body was found.
     *
     * A class where all methods are trivial (empty body, return null/scalar/constant)
     * gets LCOM=1 to avoid misleading high values for Null Objects and similar patterns.
     */
    private bool $hasNonTrivialMethod = false;

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

    public function addMethodCall(string $callerMethod, string $calledMethod): void
    {
        if (!isset($this->methodCalls[$callerMethod])) {
            $this->methodCalls[$callerMethod] = [];
        }
        $this->methodCalls[$callerMethod][$calledMethod] = true;
    }

    public function markStatic(string $method): void
    {
        $this->staticMethods[$method] = true;
    }

    public function markNonTrivial(): void
    {
        $this->hasNonTrivialMethod = true;
    }

    /**
     * Whether all methods in this class have trivial bodies.
     *
     * A trivial method is one with an empty body or that simply returns
     * null, a scalar, or a constant. Classes with only trivial methods
     * (e.g., Null Objects) should get LCOM=1 instead of N disconnected components.
     */
    public function hasOnlyTrivialMethods(): bool
    {
        return !$this->hasNonTrivialMethod && $this->getMethodCount() > 0;
    }

    public function getMethodCount(): int
    {
        return \count($this->methods);
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
     * - Edges = (m1, m2) if m1 and m2 share a property OR one calls the other via $this->
     * - Static methods are excluded from the graph
     *
     * @return int Number of connected components (1 = perfectly cohesive)
     */
    public function calculateLcom(): int
    {
        // Exclude static methods from the graph
        $methods = array_values(array_filter(
            $this->getMethods(),
            fn(string $m): bool => !isset($this->staticMethods[$m]),
        ));
        $count = \count($methods);

        if ($count === 0) {
            return 0;
        }

        if ($count === 1) {
            return 1;
        }

        $methodSet = array_flip($methods);

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

        // Add edges for method calls ($this->method())
        foreach ($this->methodCalls as $caller => $callees) {
            if (!isset($methodSet[$caller])) {
                continue;
            }
            foreach ($callees as $callee => $_) {
                if (isset($methodSet[$callee]) && $caller !== $callee) {
                    $adjacency[$caller][] = $callee;
                    $adjacency[$callee][] = $caller;
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
