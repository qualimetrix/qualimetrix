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
 * bodies reach through direct calls and that no other method reaches: it leaves the graph
 * with the lifecycle body it serves. Every method that is neither lifecycle nor such a
 * candidate keeps what it calls in the graph, and so do the closures and first-class
 * callables that lifecycle bodies and candidates register, since those run after
 * construction. Reachability uses effective names after trait composition.
 * Calls on another instance, array callables and `parent::` calls are not recorded, so
 * a helper used only through them is treated as an initialization helper.
 */
final class LcomMethodPopulation
{
    /** @var array<string, true> */
    private readonly array $methods;

    /** @var array<string, true> */
    private readonly array $staticMethods;

    /** @var array<string, true> */
    private readonly array $statelessMethods;

    /** @var array<string, array<string, true>> */
    private readonly array $propertyAccesses;

    /**
     * Folded caller => folded callees, direct and deferred alike.
     *
     * @var array<string, array<string, true>>
     */
    private readonly array $methodCalls;

    /** @var array<string, array<string, true>> */
    private readonly array $directCalls;

    /** @var array<string, array<string, true>> */
    private readonly array $deferredCalls;

    /** @var array<string, TraitMethodSource> */
    private readonly array $declaredMethods;

    /**
     * @param array{
     *     methods: array<string, true>,
     *     staticMethods: array<string, true>,
     *     statelessMethods: array<string, true>,
     *     declaredMethods: array<string, TraitMethodSource>,
     *     propertyAccesses: array<string, array<string, true>>,
     *     methodCalls: array<string, array<string, true>>,
     *     deferredMethodCalls: array<string, array<string, true>>
     * } $state
     */
    public function __construct(array $state)
    {
        $this->methods = $state['methods'];
        $this->staticMethods = $state['staticMethods'];
        $this->statelessMethods = $state['statelessMethods'];
        $this->declaredMethods = $state['declaredMethods'];
        $this->propertyAccesses = $state['propertyAccesses'];
        $this->directCalls = self::foldCallers($state['methodCalls']);
        $this->deferredCalls = self::foldCallers($state['deferredMethodCalls']);
        $calls = $this->directCalls;
        foreach ($this->deferredCalls as $caller => $callees) {
            $calls[$caller] = ($calls[$caller] ?? []) + $callees;
        }
        $this->methodCalls = $calls;
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
        $excludeSet = array_fill_keys(array_map(ClassNameSpelling::fold(...), $excludeMethods), true);
        $excludeSet += $this->initializationHelpers();
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
        if (!isset($this->statelessMethods[$method])) {
            return false;
        }
        if (isset($this->propertyAccesses[$method]) && $this->propertyAccesses[$method] !== []) {
            return false;
        }
        $identity = ClassNameSpelling::fold($method);
        if (($this->methodCalls[$identity] ?? []) !== []) {
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
     * Folded names of private instance methods that only lifecycle bodies reach.
     *
     * @return array<string, true>
     */
    private function initializationHelpers(): array
    {
        $names = [];
        foreach (array_keys($this->methods) as $method) {
            $names[ClassNameSpelling::fold($method)] ??= $method;
        }
        $lifecycle = array_fill_keys(array_keys(array_filter($names, self::isLifecycle(...))), true);
        $candidates = $lifecycle === [] ? [] : $this->initializationCandidates($names, $lifecycle);
        if ($candidates === []) {
            return [];
        }
        $roots = array_diff_key($names, $lifecycle, $candidates);
        foreach (array_keys($lifecycle + $candidates) as $key) {
            $roots += $this->deferredCalls[$key] ?? [];
        }

        return array_diff_key($candidates, $this->reachable(array_keys(array_diff_key($roots, $lifecycle)), $this->methodCalls, $lifecycle));
    }

    /**
     * Private instance methods that lifecycle bodies reach through direct calls.
     *
     * @param array<string, string> $names
     * @param array<string, true> $lifecycle
     *
     * @return array<string, true>
     */
    private function initializationCandidates(array $names, array $lifecycle): array
    {
        $candidates = [];
        foreach ($this->reachable(array_keys($lifecycle), $this->directCalls, $lifecycle) as $key => $_) {
            $method = $names[$key] ?? null;
            if ($method !== null && !isset($this->staticMethods[$method]) && ($this->declaredMethods[$key] ?? null)?->visibility === 'private') {
                $candidates[$key] = true;
            }
        }

        return $candidates;
    }

    /**
     * Folded names reached from the seeds over the given calls, never entering a blocked name.
     * A blocked seed is expanded but is not itself reached.
     *
     * @param list<string> $seeds
     * @param array<string, array<string, true>> $calls
     * @param array<string, true> $blocked
     *
     * @return array<string, true>
     */
    private function reachable(array $seeds, array $calls, array $blocked): array
    {
        $reached = [];
        $pending = $seeds;
        while ($pending !== []) {
            $current = array_pop($pending);
            foreach ($calls[$current] ?? [] as $callee => $_) {
                if (!isset($reached[$callee]) && !isset($blocked[$callee])) {
                    $reached[$callee] = true;
                    $pending[] = $callee;
                }
            }
        }
        foreach ($seeds as $seed) {
            if (!isset($blocked[$seed])) {
                $reached[$seed] = true;
            }
        }

        return $reached;
    }

    /**
     * @param array<string, array<string, true>> $calls
     *
     * @return array<string, array<string, true>>
     */
    private static function foldCallers(array $calls): array
    {
        $folded = [];
        foreach ($calls as $caller => $callees) {
            $key = ClassNameSpelling::fold($caller);
            $folded[$key] = ($folded[$key] ?? []) + $callees;
        }

        return $folded;
    }
}
