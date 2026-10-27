<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Yield_;
use PhpParser\Node\Expr\YieldFrom;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Foreach_;

/** Captures what method bodies do for class cohesion: own property accesses, state hand-offs, own call edges and references. */
final class CohesionAstFacts
{
    /**
     * Records what a node in a method body does now: an own property access, a hand-off of
     * own state to later code (a property bound by reference, a generator body), an own call
     * edge (a first-class callable is a deferred one) and any other own-method reference.
     */
    public static function captureBody(Node $node, LcomClassData $data, string $method): void
    {
        if (self::isOwnPropertyFetch($node)) {
            if ($node->name instanceof Identifier) {
                $data->addPropertyAccess($method, $node->name->toString());
            }

            return;
        }
        $handedOff = self::boundProperties($node);
        if ($node instanceof Yield_ || $node instanceof YieldFrom) {
            $handedOff[] = LcomMethodPopulation::ANY_PROPERTY;
        }
        foreach ($handedOff as $property) {
            $data->addDeferredPropertyAccess($method, $property);
        }
        $edge = self::callEdge($node);
        if ($edge === null) {
            self::captureReference($node, $data, $method);
        } elseif ($edge[1]) {
            $data->addDeferredMethodCall($method, $edge[0]);
        } else {
            $data->addMethodCall($method, $edge[0]);
        }
    }

    /** Records what a closure, arrow function or nested function in a method body does: all of it is deferred. */
    public static function captureDeferredBody(Node $node, LcomClassData $data, string $method): void
    {
        $properties = self::isOwnPropertyFetch($node) ? [self::propertyName($node)] : self::boundProperties($node);
        foreach ($properties as $property) {
            $data->addDeferredPropertyAccess($method, $property);
        }
        $edge = self::callEdge($node);
        if ($edge === null) {
            self::captureReference($node, $data, $method);
        } else {
            $data->addDeferredMethodCall($method, $edge[0]);
        }
    }

    /**
     * The callee of an LCOM4 call edge — `$this->m()`, `self::m()` or `static::m()` with a
     * literal name — and whether it is a first-class callable, which only names the method.
     *
     * @return array{string, bool}|null
     */
    private static function callEdge(Node $node): ?array
    {
        $own = match (true) {
            $node instanceof MethodCall => CohesionOwnCalls::isThis($node->var),
            $node instanceof StaticCall => $node->class instanceof Name && \in_array($node->class->toLowerString(), ['self', 'static'], true),
            default => false,
        };
        if (!$own || !($node instanceof MethodCall || $node instanceof StaticCall) || !$node->name instanceof Identifier) {
            return null;
        }

        return [$node->name->toString(), $node->isFirstClassCallable()];
    }

    private static function captureReference(Node $node, LcomClassData $data, string $method): void
    {
        $referenced = CohesionOwnCalls::referenced($node, $data);
        if ($referenced !== null) {
            $data->addMethodReference($method, $referenced);
        }
    }

    /**
     * Own properties whose storage a node binds by reference: on either side of `=&`, as a
     * by-reference array item, as a by-reference `foreach` value, or through a destructuring
     * pattern that takes an element by reference.
     *
     * @return list<string>
     */
    private static function boundProperties(Node $node): array
    {
        $bound = match (true) {
            $node instanceof AssignRef => [$node->var, $node->expr],
            $node instanceof Array_ => array_map(static fn($item): ?Node\Expr => $item->byRef ? $item->value : null, $node->items),
            $node instanceof Assign && self::destructuresByReference($node->var) => [$node->expr],
            $node instanceof Foreach_ && ($node->byRef || self::destructuresByReference($node->valueVar)) => [$node->expr],
            default => [],
        };

        return array_values(array_filter(array_map(self::storageProperty(...), $bound), static fn(?string $property): bool => $property !== null));
    }

    /** Whether a destructuring pattern takes any element by reference, at any depth. */
    private static function destructuresByReference(Node $pattern): bool
    {
        if (!$pattern instanceof List_ && !$pattern instanceof Array_) {
            return false;
        }
        foreach ($pattern->items as $item) {
            if ($item !== null && ($item->byRef || self::destructuresByReference($item->value))) {
                return true;
            }
        }

        return false;
    }

    /** The own property whose storage an expression names, including an element or nested member of it. */
    private static function storageProperty(?Node $node): ?string
    {
        while ($node instanceof ArrayDimFetch || $node instanceof PropertyFetch) {
            if (self::isOwnPropertyFetch($node)) {
                return self::propertyName($node);
            }
            $node = $node->var;
        }

        return null;
    }

    /** @phpstan-assert-if-true PropertyFetch $node */
    private static function isOwnPropertyFetch(Node $node): bool
    {
        return $node instanceof PropertyFetch && CohesionOwnCalls::isThis($node->var);
    }

    /** A literal property name, or {@see LcomMethodPopulation::ANY_PROPERTY} for a dynamic one. */
    private static function propertyName(PropertyFetch $node): string
    {
        return $node->name instanceof Identifier ? $node->name->toString() : LcomMethodPopulation::ANY_PROPERTY;
    }
}
