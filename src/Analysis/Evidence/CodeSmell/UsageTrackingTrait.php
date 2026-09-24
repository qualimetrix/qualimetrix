<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\MagicConst;
use PhpParser\Node\Scalar\String_;

/**
 * Shared logic for classifying AST nodes as member usages.
 *
 * Recognises six patterns:
 * - $this->method() / $sameClass->method() → usedMethods (lowercase: method names are case-insensitive)
 * - self::method() / static:: → usedMethods
 * - a literal callable [$this, 'method'] / [self::class, 'method'] / [static::class, …] → usedMethods
 * - $this->property           → usedProperties
 * - self::$prop / static::    → usedProperties
 * - self::CONST / static::    → usedConstants
 */
trait UsageTrackingTrait
{
    /**
     * Classify a single AST node and record the referenced member in $data.
     *
     * @param array<string, true> $sameClassReceiverVariables
     * @param string|null $callingMethod Lowercase name of the enclosing method; a call to it is recursion, not a usage
     */
    private function trackUsage(
        Node $node,
        UnusedPrivateClassData $data,
        array $sameClassReceiverVariables = [],
        ?string $callingMethod = null,
    ): void {
        $method = $this->referencedMethod($node, $sameClassReceiverVariables);
        if ($method !== null) {
            if ($method !== $callingMethod) {
                $data->usedMethods[$method] = true;
            }

            return;
        }

        $property = $this->referencedProperty($node);
        if ($property !== null) {
            $data->usedProperties[$property] = true;

            return;
        }

        $constant = $this->referencedConstant($node);
        if ($constant !== null) {
            $data->usedConstants[$constant] = true;
        }
    }

    /**
     * @param array<string, true> $sameClassReceiverVariables
     *
     * @return ?string Lowercase method name
     */
    private function referencedMethod(Node $node, array $sameClassReceiverVariables): ?string
    {
        return match (true) {
            $node instanceof MethodCall => $this->isSameClassReceiver($node->var, $sameClassReceiverVariables)
                ? $this->lowerIdentifier($node->name)
                : null,
            $node instanceof StaticCall => $this->isSelfOrStaticClass($node->class) ? $this->lowerIdentifier($node->name) : null,
            $node instanceof Array_ => $this->callableArrayMethod($node, $sameClassReceiverVariables),
            default => null,
        };
    }

    private function referencedProperty(Node $node): ?string
    {
        if ($node instanceof PropertyFetch) {
            return $node->var instanceof Variable && $node->var->name === 'this' && $node->name instanceof Identifier
                ? $node->name->toString()
                : null;
        }

        return $node instanceof StaticPropertyFetch
            && $this->isSelfOrStaticClass($node->class)
            && $node->name instanceof Node\VarLikeIdentifier
            ? $node->name->toString()
            : null;
    }

    private function referencedConstant(Node $node): ?string
    {
        return $node instanceof ClassConstFetch
            && $this->isSelfOrStaticClass($node->class)
            && $node->name instanceof Identifier
            && $node->name->toString() !== 'class'
            ? $node->name->toString()
            : null;
    }

    /**
     * The method of a literal callable array whose receiver is this class.
     *
     * @param array<string, true> $sameClassReceiverVariables
     */
    private function callableArrayMethod(Array_ $node, array $sameClassReceiverVariables): ?string
    {
        if (\count($node->items) !== 2) {
            return null;
        }

        [$receiver, $method] = $node->items;
        if (!$this->isPositionalItem($receiver) || !$this->isPositionalItem($method) || !$method->value instanceof String_) {
            return null;
        }

        return $this->isSameClassCallableReceiver($receiver->value, $sameClassReceiverVariables)
            ? strtolower($method->value->value)
            : null;
    }

    /**
     * @phpstan-assert-if-true ArrayItem $item
     */
    private function isPositionalItem(?ArrayItem $item): bool
    {
        return $item !== null && $item->key === null && !$item->unpack;
    }

    /**
     * @param array<string, true> $sameClassReceiverVariables
     */
    private function isSameClassCallableReceiver(Expr $receiver, array $sameClassReceiverVariables): bool
    {
        if ($receiver instanceof ClassConstFetch) {
            return $this->isSelfOrStaticClass($receiver->class)
                && $receiver->name instanceof Identifier
                && $receiver->name->toLowerString() === 'class';
        }

        return $receiver instanceof MagicConst\Class_ || $this->isSameClassReceiver($receiver, $sameClassReceiverVariables);
    }

    /**
     * $this, or a variable proven to hold a new self/static instance.
     *
     * @param array<string, true> $sameClassReceiverVariables
     */
    private function isSameClassReceiver(Expr $receiver, array $sameClassReceiverVariables): bool
    {
        return $receiver instanceof Variable
            && ($receiver->name === 'this' || (\is_string($receiver->name) && isset($sameClassReceiverVariables[$receiver->name])));
    }

    private function lowerIdentifier(Node $name): ?string
    {
        return $name instanceof Identifier ? $name->toLowerString() : null;
    }

    private function isSelfOrStaticClass(Node $class): bool
    {
        return $class instanceof Name && $this->isSelfOrStatic($class);
    }

    private function isSelfOrStatic(Name $name): bool
    {
        $lower = $name->toLowerString();

        return $lower === 'self' || $lower === 'static';
    }
}
