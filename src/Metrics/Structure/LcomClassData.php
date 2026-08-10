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
     * Set of stateless constant methods (grouped into a virtual node in LCOM graph).
     *
     * A method is "stateless constant" if it has no property access, no instance method calls,
     * and its body is a single return of a scalar, constant, class constant, or array of those.
     * These methods (e.g., getName(), getDescription()) are effectively metadata and should
     * not each form a separate connected component.
     *
     * @var array<string, true>
     */
    private array $statelessMethods = [];

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
        public readonly int $startFilePos = 0,
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

    public function markStatelessConstant(string $method): void
    {
        $this->statelessMethods[$method] = true;
    }

    /**
     * Whether a method was classified as a stateless constant during AST traversal.
     */
    public function isStatelessConstant(string $method): bool
    {
        return isset($this->statelessMethods[$method]);
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
     * - Stateless constant methods are merged into a single virtual node to reduce
     *   false positives from interface-mandated metadata methods (e.g., getName())
     *
     * Delegates the graph-construction and component-counting phases to
     * {@see LcomGraphCalculator}; this method owns only method filtering and the
     * stateful/stateless classification phase.
     *
     * @param list<string> $excludeMethods Method names to exclude from the LCOM graph
     *
     * @return int Number of connected components (1 = perfectly cohesive)
     */
    public function calculateLcom(array $excludeMethods = []): int
    {
        // Exclude static methods and explicitly excluded methods from the graph
        $excludeSet = $excludeMethods !== [] ? array_flip($excludeMethods) : [];
        $methods = array_values(array_filter(
            $this->getMethods(),
            fn(string $m): bool => !isset($this->staticMethods[$m]) && !isset($excludeSet[$m]),
        ));
        $count = \count($methods);

        if ($count === 0) {
            return 0;
        }

        if ($count === 1) {
            return 1;
        }

        // Classify methods: stateless constant methods that have no property access
        // AND no instance method calls are merged into a virtual node.
        // Note: only methods already marked by the visitor AND confirmed to have no
        // property access / method calls in the collected data are treated as stateless.
        [$statefulMethods, $statelessInGraph] = $this->partitionByStatelessness($methods);

        $graph = new LcomGraphCalculator($this->propertyAccesses, $this->methodCalls);

        // If no stateless methods, proceed with standard algorithm
        if ($statelessInGraph === []) {
            return $graph->countComponents($methods);
        }

        return $graph->countComponentsWithStatelessMerge($statefulMethods, $statelessInGraph);
    }

    /**
     * Split filtered methods into the stateful ones and the set merged as stateless.
     *
     * @param list<string> $methods
     *
     * @return array{0: list<string>, 1: array<string, true>}
     */
    private function partitionByStatelessness(array $methods): array
    {
        $statelessInGraph = [];
        $statefulMethods = [];

        foreach ($methods as $method) {
            if ($this->isEffectivelyStateless($method)) {
                $statelessInGraph[$method] = true;
            } else {
                $statefulMethods[] = $method;
            }
        }

        return [$statefulMethods, $statelessInGraph];
    }

    /**
     * Whether a method is effectively stateless in the LCOM graph.
     *
     * A method is effectively stateless if it was marked as a stateless constant
     * during AST traversal (trivial body returning a constant) AND has no property
     * access and no instance method calls in the collected data.
     */
    private function isEffectivelyStateless(string $method): bool
    {
        if (!isset($this->statelessMethods[$method])) {
            return false;
        }

        // Double-check: no property access collected
        if (isset($this->propertyAccesses[$method]) && $this->propertyAccesses[$method] !== []) {
            return false;
        }

        // Double-check: no instance method calls collected
        if (isset($this->methodCalls[$method]) && $this->methodCalls[$method] !== []) {
            return false;
        }

        return true;
    }
}
