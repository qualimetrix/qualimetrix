<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

use Qualimetrix\Core\Symbol\ClassNameSpelling;
use Qualimetrix\Core\Symbol\TraitComposition\Contract\TraitMethodSource;
use Qualimetrix\Core\Symbol\TraitComposition\Contract\TraitUseFacts;

/**
 * Data structure for LCOM calculation.
 *
 * Tracks methods, their property accesses and method calls for a single class.
 *
 * @qmx-threshold size.method-count warning=21 error=21 -- The graph record exposes declaration, method-access and call facts to composition and calculation; splitting its accessors would duplicate the same class identity and graph state.
 */
final class LcomClassData
{
    /**
     * Concrete method-body names before effective-name graph exclusions.
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
     * Map of method => set of properties it hands to later code: accessed inside a closure,
     * an arrow function or a nested function, or bound by reference.
     *
     * @var array<string, array<string, true>>
     */
    private array $deferredPropertyAccesses = [];

    /**
     * Map of method => set of methods it calls directly (via $this->method(), self:: or static::).
     *
     * @var array<string, array<string, true>>
     */
    private array $methodCalls = [];

    /**
     * Map of method => set of methods it calls from a closure, an arrow function or a
     * first-class callable: code that runs after the body, not during it.
     *
     * @var array<string, array<string, true>>
     */
    private array $deferredMethodCalls = [];

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

    public function addDeferredPropertyAccess(string $methodName, string $propertyName): void
    {
        if (!isset($this->deferredPropertyAccesses[$methodName])) {
            $this->deferredPropertyAccesses[$methodName] = [];
        }
        $this->deferredPropertyAccesses[$methodName][$propertyName] = true;
    }

    public function addMethodCall(string $callerMethod, string $calledMethod): void
    {
        if (!isset($this->methodCalls[$callerMethod])) {
            $this->methodCalls[$callerMethod] = [];
        }
        $this->methodCalls[$callerMethod][ClassNameSpelling::fold($calledMethod)] = true;
    }

    public function addDeferredMethodCall(string $callerMethod, string $calledMethod): void
    {
        if (!isset($this->deferredMethodCalls[$callerMethod])) {
            $this->deferredMethodCalls[$callerMethod] = [];
        }
        $this->deferredMethodCalls[$callerMethod][ClassNameSpelling::fold($calledMethod)] = true;
    }

    public function markStatic(string $method): void
    {
        $this->staticMethods[$method] = true;
    }

    public function markStatelessConstant(string $method): void
    {
        $this->statelessMethods[$method] = true;
    }

    /**
     * @return list<string>
     */
    public function getMethods(): array
    {
        return array_keys($this->methods);
    }

    /**
     * Direct and deferred accesses alike.
     *
     * @return list<string>
     */
    public function getPropertiesAccessedBy(string $methodName): array
    {
        return array_keys(($this->propertyAccesses[$methodName] ?? []) + ($this->deferredPropertyAccesses[$methodName] ?? []));
    }

    /**
     * Direct and deferred callees alike: both reach the callee's state from this method.
     *
     * @return list<string>
     */
    public function callsFrom(string $methodName): array
    {
        return array_keys(($this->methodCalls[$methodName] ?? []) + ($this->deferredMethodCalls[$methodName] ?? []));
    }

    public function hasGraphMethod(string $methodName): bool
    {
        return isset($this->methods[$methodName]) && !LcomMethodPopulation::isLifecycle($methodName);
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
        if (!isset($source->methods[$original])) {
            return;
        }
        $this->addMethod($effectiveName);
        if (isset($source->staticMethods[$original])) {
            $this->markStatic($effectiveName);
        }
        if (isset($source->statelessMethods[$original])) {
            $this->markStatelessConstant($effectiveName);
        }
        $this->importAccesses($effectiveName, $source, $original);
    }

    private function importAccesses(string $effectiveName, self $source, string $original): void
    {
        $imports = [
            [$source->propertyAccesses, $this->addPropertyAccess(...)],
            [$source->deferredPropertyAccesses, $this->addDeferredPropertyAccess(...)],
            [$source->methodCalls, $this->addMethodCall(...)],
            [$source->deferredMethodCalls, $this->addDeferredMethodCall(...)],
        ];
        foreach ($imports as [$facts, $add]) {
            foreach (array_keys($facts[$original] ?? []) as $member) {
                $add($effectiveName, $member);
            }
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
     *     deferredPropertyAccesses: array<string, array<string, true>>,
     *     methodCalls: array<string, array<string, true>>,
     *     deferredMethodCalls: array<string, array<string, true>>
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
            'deferredPropertyAccesses' => $this->deferredPropertyAccesses,
            'methodCalls' => $this->methodCalls,
            'deferredMethodCalls' => $this->deferredMethodCalls,
        ];
    }

    /**
     * Calculate LCOM4 (Lack of Cohesion of Methods).
     *
     * LCOM4 is the number of connected components in the graph where:
     * - Vertices = methods
     * - Edges = (m1, m2) if m1 and m2 share a property OR one calls the other via $this->,
     *   self:: or static::, directly or from a closure or first-class callable
     * - Static, constructor and destructor methods are excluded from the graph
     *   after trait composition resolves effective method names
     * - Initialization helpers leave the graph with the constructor: private instance
     *   methods that only constructor or destructor bodies reach through direct calls
     *   and that hand no state to later code (see {@see LcomMethodPopulation})
     * - Stateless constant methods are merged into a single virtual node to reduce
     *   false positives from interface-mandated metadata methods (e.g., getName())
     *
     * Delegates the graph-construction and component-counting phases to
     * {@see LcomGraphCalculator}; this method owns only method filtering and the
     * stateful/stateless classification phase.
     *
     * @param list<string> $excludeMethods Method names to exclude from the LCOM graph
     * @param int $unreadTraitCount Used traits whose bodies were not read; any keeps every helper
     *
     * @return int Number of connected components (1 = perfectly cohesive)
     */
    public function calculateLcom(array $excludeMethods = [], int $unreadTraitCount = 0): int
    {
        $population = new LcomMethodPopulation($this->factState(), $unreadTraitCount);
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

        $graph = $population->graph();
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
    public function lcomMethodCount(array $excludeMethods = [], int $unreadTraitCount = 0): int
    {
        return (new LcomMethodPopulation($this->factState(), $unreadTraitCount))->count($excludeMethods);
    }

}
