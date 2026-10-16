<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

use Qualimetrix\Core\Symbol\ClassNameSpelling;

/** Selects the LCOM graph population after lifecycle, static, configured, and stateless handling. */
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

    /** @var array<string, array<string, true>> */
    private readonly array $methodCalls;

    /**
     * @param array{
     *     methods: array<string, true>,
     *     staticMethods: array<string, true>,
     *     statelessMethods: array<string, true>,
     *     propertyAccesses: array<string, array<string, true>>,
     *     methodCalls: array<string, array<string, true>>
     * } $state
     */
    public function __construct(array $state)
    {
        $this->methods = $state['methods'];
        $this->staticMethods = $state['staticMethods'];
        $this->statelessMethods = $state['statelessMethods'];
        $this->propertyAccesses = $state['propertyAccesses'];
        $this->methodCalls = $state['methodCalls'];
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
        if (isset($this->methodCalls[$method]) && $this->methodCalls[$method] !== []) {
            return false;
        }

        $identity = ClassNameSpelling::fold($method);
        foreach ($this->methodCalls as $caller => $callees) {
            $callerKey = ClassNameSpelling::fold($caller);
            if ($callerKey !== $identity && isset($eligible[$callerKey], $callees[$identity])) {
                return false;
            }
        }

        return true;
    }
}
