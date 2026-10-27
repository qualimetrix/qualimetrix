<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\MagicConst;
use PhpParser\Node\Scalar\String_;

/**
 * Names the own method a node refers to without an LCOM4 call edge: a call through the own
 * class name or `$this::`, a dynamically named own call, an unkeyed two-element callable array
 * or `new ReflectionMethod(...)` whose first element names this object or class, or a callable
 * string `'self::m'`, `'static::m'` or `'App\Own::m'`. A string built from such a prefix, a
 * concatenation starting with the own class name, and a non-literal method name in any of
 * these forms give {@see LcomMethodPopulation::ANY_METHOD}. Over-matching only keeps helpers.
 */
final class CohesionOwnCalls
{
    public static function referenced(Node $node, LcomClassData $data): ?string
    {
        $pair = self::ownerAndMethod($node);
        if ($pair !== null) {
            return self::isOwnValue($pair[0], $data) ? self::methodName($pair[1]) : null;
        }

        return self::textReference($node, $data);
    }

    /**
     * The owner and method of a call, an unkeyed two-element array or a two-argument
     * `new ReflectionMethod(...)`.
     *
     * @return array{Node, Node}|null
     */
    private static function ownerAndMethod(Node $node): ?array
    {
        $parts = match (true) {
            $node instanceof MethodCall => [$node->var, $node->name],
            $node instanceof StaticCall => [$node->class, $node->name],
            $node instanceof Array_ => array_map(static fn($item): ?Node => $item->key === null ? $item->value : null, $node->items),
            $node instanceof New_ && self::isNamed($node->class, ['reflectionmethod']) => array_map(static fn(Node\Arg $argument): Node => $argument->value, $node->getArgs()),
            default => [],
        };

        return \count($parts) === 2 && isset($parts[0], $parts[1]) ? [$parts[0], $parts[1]] : null;
    }

    /** A literal name, or {@see LcomMethodPopulation::ANY_METHOD} when the facts cannot read it. */
    private static function methodName(Node $name): string
    {
        return match (true) {
            $name instanceof Identifier => $name->toString(),
            $name instanceof String_ => $name->value,
            default => LcomMethodPopulation::ANY_METHOD,
        };
    }

    private static function textReference(Node $node, LcomClassData $data): ?string
    {
        $leading = self::leadingText($node, $data);
        $own = '(?:self|static|' . preg_quote($data->declaration()->qualifiedName(), '/') . ')';
        if ($leading === null || preg_match('/^\\\\?' . $own . '::(\w*)(.*)$/is', $leading[0], $match) !== 1) {
            return null;
        }

        return $leading[1] && $match[1] !== '' && $match[2] === '' ? $match[1] : LcomMethodPopulation::ANY_METHOD;
    }

    /**
     * The literal text a string expression starts with, and whether that is all of it. A
     * concatenation starting with the own class name reads as `self::`.
     *
     * @return array{string, bool}|null
     */
    private static function leadingText(Node $node, LcomClassData $data): ?array
    {
        $first = $node instanceof InterpolatedString ? ($node->parts[0] ?? null) : null;

        return match (true) {
            $node instanceof String_ => [$node->value, true],
            $first instanceof InterpolatedStringPart => [$first->value, false],
            $node instanceof Concat && self::isOwnClassName($node->left, $data) => ['self::', false],
            default => null,
        };
    }

    /**
     * Whether an expression or class position denotes this object or its class: `$this`,
     * `self`, `static` or the own fully qualified name, as a name or a string, or a class name
     * value ({@see self::isOwnClassName()}). A short name in a namespace denotes another class.
     */
    private static function isOwnValue(Node $node, LcomClassData $data): bool
    {
        $resolved = $node instanceof Name ? $node->getAttribute('resolvedName') : null;

        return match (true) {
            $node instanceof Name => self::isOwnName(($resolved instanceof Name ? $resolved : $node)->toString(), $data),
            $node instanceof String_ => self::isOwnName(ltrim($node->value, '\\'), $data),
            default => self::isThis($node) || self::isOwnClassName($node, $data),
        };
    }

    /** `__CLASS__`, `self::class`, `static::class`, `Own::class`, `$this::class`, `get_class($this)`, `get_class()` or `get_called_class()`. */
    private static function isOwnClassName(Node $node, LcomClassData $data): bool
    {
        if ($node instanceof ClassConstFetch) {
            return $node->name instanceof Identifier && $node->name->toLowerString() === 'class' && self::isOwnValue($node->class, $data);
        }

        return $node instanceof FuncCall ? self::callsOwnClassFunction($node) : $node instanceof MagicConst\Class_;
    }

    private static function callsOwnClassFunction(FuncCall $node): bool
    {
        $arguments = $node->getRawArgs();

        return self::isNamed($node->name, ['get_class', 'get_called_class'])
            && ($arguments === [] || $arguments[0] instanceof Node\Arg && self::isThis($arguments[0]->value));
    }

    /** @param list<string> $names lower-case unqualified names */
    private static function isNamed(Node $name, array $names): bool
    {
        return $name instanceof Name && \in_array(strtolower($name->getLast()), $names, true);
    }

    public static function isThis(Node $node): bool
    {
        return $node instanceof Variable && $node->name === 'this';
    }

    private static function isOwnName(string $name, LcomClassData $data): bool
    {
        return \in_array(strtolower($name), ['self', 'static', strtolower($data->declaration()->qualifiedName())], true);
    }
}
