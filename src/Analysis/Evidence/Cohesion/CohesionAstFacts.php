<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\Yield_;
use PhpParser\Node\Expr\YieldFrom;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\TraitUse;

/** Captures declaration, own property-access and own-call facts for class cohesion, keeping deferred facts apart. */
final class CohesionAstFacts
{
    public static function captureMember(TraitUse|Property $node, ?LcomClassData $data): void
    {
        if ($data === null) {
            return;
        }
        if ($node instanceof TraitUse) {
            $data->declaration()->addTraitUse(CohesionTraitUses::fromNode($node));

            return;
        }
        if (!$node->isStatic()) {
            $data->declaration()->incrementInstancePropertyCount(\count($node->props));
        }
    }

    public static function captureMethod(ClassMethod $node, ?LcomClassData $data): void
    {
        if ($data === null) {
            return;
        }
        $name = $node->name->toString();
        $visibility = $node->isPrivate() ? 'private' : ($node->isProtected() ? 'protected' : 'public');
        $data->declaration()->declareMethod($name, $visibility, $node->isAbstract());
        if (strtolower($name) !== '__construct') {
            return;
        }
        foreach ($node->params as $param) {
            if ($param->flags !== 0) {
                $data->declaration()->incrementInstancePropertyCount();
            }
        }
    }

    /**
     * Records what a node in a method body does now: an own property access, a hand-off of
     * own state to later code (a property bound by reference, a generator body) and an own
     * call; a reference to a method is a deferred call.
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
        $callee = CohesionOwnCalls::invoked($node, $data);
        if ($callee !== null) {
            $data->addMethodCall($method, $callee);

            return;
        }
        $referenced = CohesionOwnCalls::referenced($node, $data);
        if ($referenced !== null) {
            $data->addDeferredMethodCall($method, $referenced);
        }
    }

    /** Records what a closure, arrow function or nested function in a method body does: all of it is deferred. */
    public static function captureDeferredBody(Node $node, LcomClassData $data, string $method): void
    {
        $properties = self::isOwnPropertyFetch($node) ? [self::propertyName($node)] : self::boundProperties($node);
        foreach ($properties as $property) {
            $data->addDeferredPropertyAccess($method, $property);
        }
        $callee = CohesionOwnCalls::invoked($node, $data) ?? CohesionOwnCalls::referenced($node, $data);
        if ($callee !== null) {
            $data->addDeferredMethodCall($method, $callee);
        }
    }

    /**
     * Own properties whose storage a node binds by reference: on either side of `=&`, as a
     * by-reference array item, or as a by-reference `foreach` value.
     *
     * @return list<string>
     */
    private static function boundProperties(Node $node): array
    {
        $bound = match (true) {
            $node instanceof AssignRef => [$node->var, $node->expr],
            $node instanceof Array_ => array_map(static fn($item): ?Node\Expr => $item->byRef ? $item->value : null, $node->items),
            $node instanceof Foreach_ && $node->byRef => [$node->expr],
            default => [],
        };

        return array_values(array_filter(array_map(self::storageProperty(...), $bound), static fn(?string $property): bool => $property !== null));
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
        return $node instanceof PropertyFetch && $node->var instanceof Variable && $node->var->name === 'this';
    }

    /** A literal property name, or {@see LcomMethodPopulation::ANY_PROPERTY} for a dynamic one. */
    private static function propertyName(PropertyFetch $node): string
    {
        return $node->name instanceof Identifier ? $node->name->toString() : LcomMethodPopulation::ANY_PROPERTY;
    }
}
