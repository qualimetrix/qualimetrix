<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\MagicConst;
use PhpParser\Node\Scalar\String_;
use Qualimetrix\Core\Ast\ResolvedName;

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
            $node instanceof MethodCall => $this->isSameClassReceiver($node->var, $data, $sameClassReceiverVariables)
                ? $this->lowerIdentifier($node->name)
                : null,
            $node instanceof StaticCall => $this->isOwnClassNode($node->class, $data) ? $this->lowerIdentifier($node->name) : null,
            $node instanceof Array_ => $this->callableArrayMethod($node, $data, $sameClassReceiverVariables),
            $node instanceof Arg && $node->value instanceof String_ => $this->callableStringMethod($node->value->value, $data),
            default => null,
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

    /**
     * The method of a literal callable array whose receiver is this class.
     *
     * @param array<string, true> $sameClassReceiverVariables
     */
    private function callableArrayMethod(Array_ $node, UnusedPrivateClassData $data, array $sameClassReceiverVariables): ?string
    {
        if (\count($node->items) !== 2) {
            return null;
        }

        [$receiver, $method] = $node->items;
        if (!$this->isPositionalItem($receiver) || !$this->isPositionalItem($method) || !$method->value instanceof String_) {
            return null;
        }

        return $this->isSameClassCallableReceiver($receiver->value, $data, $sameClassReceiverVariables)
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
    private function isSameClassCallableReceiver(Expr $receiver, UnusedPrivateClassData $data, array $sameClassReceiverVariables): bool
    {
        if ($receiver instanceof ClassConstFetch) {
            return $this->isOwnClassNode($receiver->class, $data)
                && $receiver->name instanceof Identifier
                && $receiver->name->toLowerString() === 'class';
        }

        if ($receiver instanceof String_) {
            return $this->isOwnClassString($receiver->value, $data);
        }

        return $receiver instanceof MagicConst\Class_ || $this->isSameClassReceiver($receiver, $data, $sameClassReceiverVariables);
    }

    /**
     * $this, or a variable proven to hold a new self/static instance.
     *
     * @param array<string, true> $sameClassReceiverVariables
     */
    private function isSameClassReceiver(Expr $receiver, UnusedPrivateClassData $data, array $sameClassReceiverVariables): bool
    {
        if ($receiver instanceof New_) {
            return $this->isOwnClassNode($receiver->class, $data);
        }

        return $receiver instanceof Variable
            && ($receiver->name === 'this' || (\is_string($receiver->name) && isset($sameClassReceiverVariables[$receiver->name])));
    }

    private function lowerIdentifier(Node $name): ?string
    {
        return $name instanceof Identifier ? $name->toLowerString() : null;
    }

    private function isOwnClassNode(Node $class, UnusedPrivateClassData $data): bool
    {
        if (!$class instanceof Name) {
            return false;
        }

        if ($this->isSelfOrStatic($class)) {
            return true;
        }

        $resolved = ResolvedName::className($class);

        return $resolved !== null && $this->isOwnClassString($resolved, $data);
    }

    private function isOwnClassString(string $class, UnusedPrivateClassData $data): bool
    {
        if (\in_array(strtolower($class), ['self', 'static'], true)) {
            return true;
        }

        $own = ($data->namespace === null || $data->namespace === '')
            ? $data->className
            : $data->namespace . '\\' . $data->className;

        return ResolvedName::sameClass(ltrim($class, '\\'), $own);
    }

    private function callableStringMethod(string $callable, UnusedPrivateClassData $data): ?string
    {
        $separator = strrpos($callable, '::');
        if ($separator === false || $separator === 0 || $separator === \strlen($callable) - 2) {
            return null;
        }

        $class = substr($callable, 0, $separator);
        $method = substr($callable, $separator + 2);

        return $this->isOwnClassString($class, $data) ? strtolower($method) : null;
    }

    private function isSelfOrStatic(Name $name): bool
    {
        $lower = $name->toLowerString();

        return $lower === 'self' || $lower === 'static';
    }
}
