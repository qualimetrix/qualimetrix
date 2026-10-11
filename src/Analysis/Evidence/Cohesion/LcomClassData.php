<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

use Qualimetrix\Core\Symbol\ClassNameSpelling;
use Qualimetrix\Core\Symbol\TraitMethodSource;
use Qualimetrix\Core\Symbol\TraitUseFacts;

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

    private CohesionDeclarationData $declaration;

    private bool $hasNonTrivialMethod = false;

    public function __construct(
        public readonly ?string $namespace = null,
        public readonly string $className = '',
        public readonly int $line = 0,
        public readonly int $startFilePos = 0,
    ) {
        $qualifiedName = ltrim(($namespace === null || $namespace === '' ? '' : $namespace . '\\') . $className, '\\');
        $this->declaration = new CohesionDeclarationData($qualifiedName);
    }

    public function addMethod(string $methodName): void
    {
        $this->methods[$methodName] = true;
        $this->declaration->addMethod($methodName);
    }

    public function __clone(): void
    {
        $this->declaration = clone $this->declaration;
    }

    public function declaration(): CohesionDeclarationData
    {
        return $this->declaration;
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
        $this->methodCalls[$callerMethod][ClassNameSpelling::fold($calledMethod)] = true;
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

    /** @return list<string> */
    public function callsFrom(string $methodName): array
    {
        return array_keys($this->methodCalls[$methodName] ?? []);
    }

    public function hasGraphMethod(string $methodName): bool
    {
        return isset($this->methods[$methodName]);
    }

    public function isStaticMethod(string $methodName): bool
    {
        return isset($this->staticMethods[$methodName]);
    }

    /** @param 'public'|'protected'|'private' $visibility */
    public function importMethod(string $effectiveName, self $source, string $originalName, string $visibility): void
    {
        $definition = $source->declaration->methodSource($originalName);
        if ($definition === null) {
            return;
        }
        $original = $definition->originalMethod;
        $this->declaration->declareMethod($effectiveName, $visibility, $definition->isAbstract);
        if (!$source->hasGraphMethod($original)) {
            return;
        }
        $this->addMethod($effectiveName);
        if (isset($source->staticMethods[$original])) {
            $this->markStatic($effectiveName);
        }
        if (isset($source->statelessMethods[$original])) {
            $this->markStatelessConstant($effectiveName);
        }
        foreach ($source->getPropertiesAccessedBy($original) as $property) {
            $this->addPropertyAccess($effectiveName, $property);
        }
        foreach ($source->callsFrom($original) as $callee) {
            $this->addMethodCall($effectiveName, $callee);
        }
    }

    /**
     * @return array{
     *     methods: array<string, true>,
     *     staticMethods: array<string, true>,
     *     statelessMethods: array<string, true>,
     *     declaredMethods: array<string, TraitMethodSource>,
     *     traitUses: list<TraitUseFacts>,
     *     instancePropertyCount: int,
     *     propertyAccesses: array<string, array<string, true>>,
     *     methodCalls: array<string, array<string, true>>,
     *     nonTrivial: bool
     * }
     */
    public function factState(): array
    {
        return [
            'methods' => $this->methods,
            'staticMethods' => $this->staticMethods,
            'statelessMethods' => $this->statelessMethods,
            'declaredMethods' => $this->declaration->methods(),
            'traitUses' => $this->declaration->traitUses(),
            'instancePropertyCount' => $this->declaration->instancePropertyCount(),
            'propertyAccesses' => $this->propertyAccesses,
            'methodCalls' => $this->methodCalls,
            'nonTrivial' => $this->hasNonTrivialMethod,
        ];
    }

    /**
     * Calculate LCOM4 (Lack of Cohesion of Methods).
     *
     * LCOM4 is the number of connected components in the graph where:
     * - Vertices = methods
     * - Edges = (m1, m2) if m1 and m2 share a property OR one calls the other via $this->
     * - Static, constructor and destructor methods are excluded from the graph
     *   (see {@see LcomVisitor::enterNode()} for why constructors/destructors are excluded)
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
        $population = new LcomMethodPopulation($this->factState());
        $methods = $population->graphMethods($excludeMethods);
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
        [$statefulMethods, $statelessInGraph] = $population->partitionByStatelessness($methods);

        $properties = $calls = [];
        foreach ($this->propertyAccesses as $method => $accesses) {
            $key = ClassNameSpelling::fold($method);
            $properties[$key] = ($properties[$key] ?? []) + $accesses;
        }
        foreach ($this->methodCalls as $method => $callees) {
            $key = ClassNameSpelling::fold($method);
            $calls[$key] = ($calls[$key] ?? []) + $callees;
        }
        $graph = new LcomGraphCalculator($properties, $calls);
        $methods = array_map(ClassNameSpelling::fold(...), $methods);
        $statefulMethods = array_map(ClassNameSpelling::fold(...), $statefulMethods);
        $statelessInGraph = array_fill_keys(array_map(ClassNameSpelling::fold(...), array_keys($statelessInGraph)), true);

        // If no stateless methods, proceed with standard algorithm
        if ($statelessInGraph === []) {
            return $graph->countComponents($methods);
        }

        return $graph->countComponentsWithStatelessMerge($statefulMethods, $statelessInGraph);
    }

    /** @param list<string> $excludeMethods */
    public function lcomMethodCount(array $excludeMethods = []): int
    {
        return (new LcomMethodPopulation($this->factState()))->count($excludeMethods);
    }

}
