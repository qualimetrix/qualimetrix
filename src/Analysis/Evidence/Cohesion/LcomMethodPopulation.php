<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

use Qualimetrix\Core\Symbol\ClassNameSpelling;
use Qualimetrix\Core\Symbol\TraitComposition\Contract\TraitMethodSource;

/**
 * Selects the LCOM graph population after lifecycle, initialization-helper, static,
 * configured, and stateless handling.
 *
 * An initialization helper is a private instance method that constructor or destructor
 * bodies reach through direct calls and that no other method reaches. A method that hands
 * instance state to later code (a property touched inside a closure, bound by reference, or
 * a generator body) registers behaviour instead and is never a candidate. Every method that
 * is neither lifecycle nor a candidate keeps what it calls, and so do the closures and first-class
 * callables that lifecycle bodies and candidates create: every such callable is treated as
 * deferred, including one invoked during construction. Any other syntactic reference to an
 * own method (a callable array or string, a reflection, a call through the own class name)
 * keeps its target too, without becoming an edge. Reachability uses effective names after
 * trait composition and never passes through a lifecycle body.
 *
 * No method leaves when the call map may be incomplete: a used trait was not read, or an
 * own method is named only dynamically ({@see self::ANY_METHOD}). Calls on another
 * instance or an alias of `$this`, nullsafe calls and `parent::` calls are not recorded,
 * so a helper used only through them is treated as an initialization helper.
 */
final class LcomMethodPopulation
{
    /** Target of an own-method reference whose name the facts cannot read, such as `$this->$name()`. */
    public const string ANY_METHOD = '*';

    /**
     * Deferred access to own state the facts cannot name: a dynamic property in a closure,
     * or the body of a generator. It marks a hand-off and is never a shared-property edge.
     */
    public const string ANY_PROPERTY = '*';

    /** @var array<string, true> */
    private readonly array $methods;

    /** @var array<string, true> */
    private readonly array $staticMethods;

    /** @var array<string, true> */
    private readonly array $statelessMethods;

    /**
     * Folded method => properties, direct and deferred alike.
     *
     * @var array<string, array<string, true>>
     */
    private readonly array $propertyAccesses;

    /**
     * Folded caller => folded callees, direct and deferred alike.
     *
     * @var array<string, array<string, true>>
     */
    private readonly array $methodCalls;

    /** @var array<string, true> */
    private readonly array $initializationHelpers;

    /**
     * @param array{
     *     methods: array<string, true>,
     *     staticMethods: array<string, true>,
     *     statelessMethods: array<string, true>,
     *     declaredMethods: array<string, TraitMethodSource>,
     *     propertyAccesses: array<string, array<string, true>>,
     *     deferredPropertyAccesses: array<string, array<string, true>>,
     *     methodCalls: array<string, array<string, true>>,
     *     deferredMethodCalls: array<string, array<string, true>>,
     *     methodReferences: array<string, array<string, true>>
     * } $state
     * @param int $unreadTraitCount used traits whose bodies were not read
     */
    public function __construct(array $state, int $unreadTraitCount)
    {
        $this->methods = $state['methods'];
        $this->staticMethods = $state['staticMethods'];
        $this->statelessMethods = $state['statelessMethods'];
        $this->propertyAccesses = array_map(
            static fn(array $properties): array => array_diff_key($properties, [self::ANY_PROPERTY => true]),
            self::foldCallers($state['propertyAccesses'], $state['deferredPropertyAccesses']),
        );
        $this->methodCalls = self::foldCallers($state['methodCalls'], $state['deferredMethodCalls']);
        $names = [];
        foreach (array_keys($this->methods) as $method) {
            $names[ClassNameSpelling::fold($method)] ??= $method;
        }
        $lifecycle = array_fill_keys(array_keys(array_filter($names, self::isLifecycle(...))), true);
        $this->initializationHelpers = self::initializationHelpers($state, $names, $lifecycle, $unreadTraitCount);
    }

    public static function isLifecycle(string $method): bool
    {
        return \in_array(ClassNameSpelling::fold($method), ['__construct', '__destruct'], true);
    }

    /**
     * @param list<string> $excludeMethods
     *
     * @return list<string>
     */
    public function graphMethods(array $excludeMethods): array
    {
        $excludeSet = array_fill_keys(array_map(ClassNameSpelling::fold(...), $excludeMethods), true) + $this->initializationHelpers;
        $methods = [];
        $seen = [];
        foreach (array_keys($this->methods) as $method) {
            $key = ClassNameSpelling::fold($method);
            if (self::isLifecycle($method) || isset($this->staticMethods[$method]) || isset($excludeSet[$key]) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $methods[] = $method;
        }

        return $methods;
    }

    /** The graph over folded names: shared properties and own calls, direct and deferred alike. */
    public function graph(): LcomGraphCalculator
    {
        return new LcomGraphCalculator($this->propertyAccesses, $this->methodCalls);
    }

    /**
     * @param list<string> $methods
     *
     * @return array{0: list<string>, 1: array<string, true>}
     */
    public function partitionByStatelessness(array $methods): array
    {
        $statelessInGraph = [];
        $statefulMethods = [];
        $eligible = array_fill_keys(array_map(ClassNameSpelling::fold(...), $methods), true);

        foreach ($methods as $method) {
            if ($this->isEffectivelyStateless($method, $eligible)) {
                $statelessInGraph[$method] = true;
            } else {
                $statefulMethods[] = $method;
            }
        }

        return [$statefulMethods, $statelessInGraph];
    }

    /** @param list<string> $excludeMethods */
    public function count(array $excludeMethods): int
    {
        $methods = $this->graphMethods($excludeMethods);
        [, $stateless] = $this->partitionByStatelessness($methods);
        $uncalledStateless = \count($stateless);

        return \count($methods) - $uncalledStateless + (int) ($uncalledStateless > 0);
    }

    /**
     * @param array<string, true> $eligible
     */
    private function isEffectivelyStateless(string $method, array $eligible): bool
    {
        $identity = ClassNameSpelling::fold($method);
        if (!isset($this->statelessMethods[$method]) || ($this->propertyAccesses[$identity] ?? []) !== [] || ($this->methodCalls[$identity] ?? []) !== []) {
            return false;
        }

        foreach ($this->methodCalls as $caller => $callees) {
            if ($caller !== $identity && isset($eligible[$caller], $callees[$identity])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{
     *     staticMethods: array<string, true>,
     *     declaredMethods: array<string, TraitMethodSource>,
     *     deferredPropertyAccesses: array<string, array<string, true>>,
     *     methodCalls: array<string, array<string, true>>,
     *     deferredMethodCalls: array<string, array<string, true>>,
     *     methodReferences: array<string, array<string, true>>
     * } $state
     * @param array<string, string> $names folded name => declared spelling of each method body
     * @param array<string, true> $lifecycle folded lifecycle names
     * @param int $unreadTraitCount used traits whose bodies were not read
     *
     * @return array<string, true> folded names of the initialization helpers
     */
    private static function initializationHelpers(array $state, array $names, array $lifecycle, int $unreadTraitCount): array
    {
        $references = self::foldCallers($state['methodReferences']);
        if ($lifecycle === [] || $unreadTraitCount > 0 || self::referencesAnyMethod($references)) {
            return [];
        }
        $allCalls = self::foldCallers($state['methodCalls'], $state['deferredMethodCalls']);
        $candidates = self::candidates($state, $names, $lifecycle);
        $roots = array_diff_key($names, $lifecycle, $candidates);
        $deferred = self::foldCallers($state['deferredMethodCalls']);
        foreach (array_keys($lifecycle + $candidates) as $key) {
            $roots += $deferred[$key] ?? [];
        }
        foreach ($references as $targets) {
            $roots += $targets;
        }

        return array_diff_key($candidates, LcomGraphCalculator::reachable(array_keys(array_diff_key($roots, $lifecycle)), $allCalls, $lifecycle));
    }

    /**
     * Unions caller => callee maps under folded caller names.
     *
     * @param array<string, array<string, true>> ...$maps
     *
     * @return array<string, array<string, true>>
     */
    private static function foldCallers(array ...$maps): array
    {
        $folded = [];
        foreach ($maps as $map) {
            foreach ($map as $caller => $callees) {
                $key = ClassNameSpelling::fold($caller);
                $folded[$key] = ($folded[$key] ?? []) + $callees;
            }
        }

        return $folded;
    }

    /**
     * Private instance methods that lifecycle bodies reach through direct calls and that hand
     * no instance state to later code.
     *
     * @param array{
     *     staticMethods: array<string, true>,
     *     declaredMethods: array<string, TraitMethodSource>,
     *     deferredPropertyAccesses: array<string, array<string, true>>,
     *     methodCalls: array<string, array<string, true>>
     * } $state
     * @param array<string, string> $names
     * @param array<string, true> $lifecycle
     *
     * @return array<string, true>
     */
    private static function candidates(array $state, array $names, array $lifecycle): array
    {
        $handsOffState = self::foldCallers($state['deferredPropertyAccesses']);
        $candidates = [];
        foreach (LcomGraphCalculator::reachable(array_keys($lifecycle), self::foldCallers($state['methodCalls']), $lifecycle) as $key => $_) {
            $method = $names[$key] ?? null;
            if ($method !== null && !isset($state['staticMethods'][$method]) && !isset($handsOffState[$key]) && ($state['declaredMethods'][$key] ?? null)?->visibility === 'private') {
                $candidates[$key] = true;
            }
        }

        return $candidates;
    }

    /** @param array<string, array<string, true>> $calls */
    private static function referencesAnyMethod(array $calls): bool
    {
        foreach ($calls as $callees) {
            if (isset($callees[self::ANY_METHOD])) {
                return true;
            }
        }

        return false;
    }
}
