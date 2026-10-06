<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;

/**
 * Shared logic for classifying AST nodes as member usages.
 *
 * Recognises member references through:
 * - $this->method() / $sameClass->method() → usedMethods (lowercase: method names are case-insensitive)
 * - self::method() / static:: → usedMethods
 * - a literal callable [$this, 'method'] / [self::class, 'method'] / [static::class, …] → usedMethods
 * - a literal callable string such as 'self::method' or 'App\Subject::method' → usedMethods
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
        $method = $this->referencedMethod($node, $data, $sameClassReceiverVariables);
        if ($method !== null) {
            if ($method !== $callingMethod) {
                $data->usedMethods[$method] = true;
            }

            return;
        }

        $property = $this->referencedProperty($node, $data);
        if ($property !== null) {
            $data->usedProperties[$property] = true;

            return;
        }

        $constant = $this->referencedConstant($node, $data);
        if ($constant !== null) {
            $data->usedConstants[$constant] = true;
        }
    }

    /**
     * @param array<string, true> $sameClassReceiverVariables
     *
     * @return ?string Lowercase method name
     */
    private function referencedMethod(Node $node, UnusedPrivateClassData $data, array $sameClassReceiverVariables): ?string
    {
        return match (true) {
            $node instanceof MethodCall => OwnClassReference::receiver($node->var, $data, $sameClassReceiverVariables)
                ? $this->lowerIdentifier($node->name)
                : null,
            $node instanceof StaticCall => $this->isOwnClassNode($node->class, $data) ? $this->lowerIdentifier($node->name) : null,
            default => LiteralCallableUse::method($node, $data, $sameClassReceiverVariables),
        };
    }

    private function referencedProperty(Node $node, UnusedPrivateClassData $data): ?string
    {
        if ($node instanceof PropertyFetch) {
            return $node->var instanceof Variable && $node->var->name === 'this' && $node->name instanceof Identifier
                ? $node->name->toString()
                : null;
        }

        return $node instanceof StaticPropertyFetch
            && $this->isOwnClassNode($node->class, $data)
            && $node->name instanceof Node\VarLikeIdentifier
            ? $node->name->toString()
            : null;
    }

    private function referencedConstant(Node $node, UnusedPrivateClassData $data): ?string
    {
        return $node instanceof ClassConstFetch
            && $this->isOwnClassNode($node->class, $data)
            && $node->name instanceof Identifier
            && $node->name->toString() !== 'class'
            ? $node->name->toString()
            : null;
    }

    private function lowerIdentifier(Node $name): ?string
    {
        return $name instanceof Identifier ? $name->toLowerString() : null;
    }

    private function isOwnClassNode(Node $class, UnusedPrivateClassData $data): bool
    {
        return OwnClassReference::node($class, $data);
    }
}
