<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\MagicConst;
use PhpParser\Node\Scalar\String_;

/** Names the own method an AST node invokes or refers to, as far as the syntax allows. */
final class CohesionOwnCalls
{
    /** The own method a call invokes now: `$this->m()`, `self::m()`, `static::m()`, `Own::m()`, `$this::m()`. */
    public static function invoked(Node $node, LcomClassData $data): ?string
    {
        return self::isOwnCall($node, $data) && !$node->isFirstClassCallable() ? self::methodName($node->name) : null;
    }

    /**
     * The own method a node only refers to: a first-class callable of an own call, a callable
     * array whose first element names this object or class (`$this`, `self::class`,
     * `static::class`, `Own::class`, `$this::class`, `__CLASS__`, `get_class($this)`,
     * `get_called_class()`), `new ReflectionMethod(...)` with such a first argument, a callable
     * string `'self::m'`, `'static::m'` or `'Own::m'`. Invoking `$this` itself reaches only the
     * public `__invoke`, a root of ordinary use, so it names no method here.
     */
    public static function referenced(Node $node, LcomClassData $data): ?string
    {
        if (self::isOwnCall($node, $data)) {
            return $node->isFirstClassCallable() ? self::methodName($node->name) : null;
        }
        $pair = self::callablePair($node);
        if ($pair !== null) {
            return self::isOwnValue($pair[0], $data) ? self::methodName($pair[1]) : null;
        }

        return self::callableStringMethod($node, $data);
    }

    /**
     * The owner and method of a two-element array or a `new ReflectionMethod(...)`.
     *
     * @return array{Node, ?Node}|null
     */
    private static function callablePair(Node $node): ?array
    {
        if ($node instanceof Array_) {
            return \count($node->items) === 2 ? [$node->items[0]->value, $node->items[1]->value] : null;
        }
        if (!$node instanceof New_ || !$node->class instanceof Name || strtolower($node->class->getLast()) !== 'reflectionmethod') {
            return null;
        }
        $arguments = $node->getArgs();

        return isset($arguments[0]) ? [$arguments[0]->value, ($arguments[1] ?? null)?->value] : null;
    }

    /** @phpstan-assert-if-true MethodCall|StaticCall $node */
    private static function isOwnCall(Node $node, LcomClassData $data): bool
    {
        return $node instanceof MethodCall && self::isThis($node->var)
            || $node instanceof StaticCall && (self::isThis($node->class) || $node->class instanceof Name && self::isOwnClass($node->class, $data));
    }

    /** A literal name, or {@see LcomMethodPopulation::ANY_METHOD} when the facts cannot name the method. */
    private static function methodName(?Node $name): string
    {
        return match (true) {
            $name instanceof Identifier => $name->toString(),
            $name instanceof String_ => $name->value,
            default => LcomMethodPopulation::ANY_METHOD,
        };
    }

    private static function callableStringMethod(Node $node, LcomClassData $data): ?string
    {
        if (!$node instanceof String_ || preg_match('/^\\\\?([\w\\\\]+)::(\w+)$/', $node->value, $match) !== 1) {
            return null;
        }

        return self::isOwnClass(new Name($match[1]), $data) ? $match[2] : null;
    }

    private static function isThis(Node $node): bool
    {
        return $node instanceof Variable && $node->name === 'this';
    }

    /** `self`, `static`, or the analysed class's own name, resolved or as written. */
    private static function isOwnClass(Name $name, LcomClassData $data): bool
    {
        $resolved = $name->getAttribute('resolvedName');
        $written = ($resolved instanceof Name ? $resolved : $name)->toString();
        $own = ltrim(($data->namespace ?? '') . '\\' . $data->className, '\\');

        return \in_array(strtolower($written), ['self', 'static', strtolower($own), strtolower($data->className)], true);
    }

    /** An expression whose value is this object or its class name. */
    private static function isOwnValue(Node $node, LcomClassData $data): bool
    {
        return self::isThis($node)
            || $node instanceof MagicConst\Class_
            || self::isOwnClassConstant($node, $data)
            || $node instanceof FuncCall && $node->name instanceof Name
                && \in_array(strtolower($node->name->getLast()), ['get_class', 'get_called_class'], true);
    }

    /** `self::class`, `static::class`, `Own::class` or `$this::class`. */
    private static function isOwnClassConstant(Node $node, LcomClassData $data): bool
    {
        return $node instanceof ClassConstFetch
            && $node->name instanceof Identifier
            && $node->name->toLowerString() === 'class'
            && (self::isThis($node->class) || $node->class instanceof Name && self::isOwnClass($node->class, $data));
    }
}
