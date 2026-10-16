<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Extraction;

use PhpParser\Node;
use PhpParser\NodeFinder;

/** Source carriers of declaration controls, independent of measured bindings. */
final readonly class DeclarationSource
{
    /** @var array<string, string> */
    private const array CLASS_NAMES = [
        'Stmt_Class' => 'class',
        'Stmt_Interface' => 'interface',
        'Stmt_Trait' => 'trait',
        'Stmt_Enum' => 'enum',
    ];

    /** @var array<string, string> */
    private const array CALLABLE_NAMES = [
        'Stmt_ClassMethod' => 'method',
        'Stmt_Function' => 'function',
        'PropertyHook' => 'hook',
    ];

    public static function describe(Node $node): string
    {
        $callable = self::anonymousCallable($node);
        if ($callable !== null) {
            return 'closure at line ' . $callable->getStartLine();
        }

        if ($node instanceof Node\Stmt\ClassLike && isset(self::CLASS_NAMES[$node->getType()])) {
            return self::CLASS_NAMES[$node->getType()] . ' ' . ($node->name?->toString() ?? 'at line ' . $node->getStartLine());
        }

        if ($node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Stmt\Function_
            || $node instanceof Node\PropertyHook) {
            return self::CALLABLE_NAMES[$node->getType()] . ' ' . $node->name->toString();
        }

        return self::memberDescription($node);
    }

    public static function anonymousCallable(Node $node): ?Node\FunctionLike
    {
        $value = self::carrierValue($node);
        while ($value instanceof Node\Expr\Assign
            || $value instanceof Node\Expr\AssignOp\Coalesce) {
            $value = $value->expr;
        }

        if (self::isAnonymousCallable($value)) {
            return $value;
        }

        return self::callableAtStart($node);
    }

    private static function carrierValue(Node $node): ?Node
    {
        return match (true) {
            $node instanceof Node\Stmt\Expression => $node->expr,
            $node instanceof Node\Stmt\Return_ => $node->expr,
            $node instanceof Node\Arg => $node->value,
            $node instanceof Node\ArrayItem => $node->value,
            default => $node,
        };
    }

    private static function callableAtStart(Node $node): ?Node\FunctionLike
    {
        $start = $node->getStartFilePos();
        if ($start < 0) {
            return null;
        }

        $callable = (new NodeFinder())->findFirst(
            $node,
            static fn(Node $candidate): bool => self::isAnonymousCallable($candidate)
                && $candidate->getStartFilePos() === $start,
        );

        return self::isAnonymousCallable($callable)
            ? $callable
            : null;
    }

    /** @phpstan-assert-if-true Node\FunctionLike $node */
    private static function isAnonymousCallable(?Node $node): bool
    {
        return $node instanceof Node\FunctionLike
            && \in_array($node->getType(), ['Expr_Closure', 'Expr_ArrowFunction'], true);
    }

    private static function memberDescription(Node $node): string
    {
        return match (true) {
            $node instanceof Node\Stmt\Property => 'property $' . $node->props[0]->name->toString(),
            $node instanceof Node\Stmt\ClassConst => 'constant ' . $node->consts[0]->name->toString(),
            $node instanceof Node\Stmt\EnumCase => 'case ' . $node->name->toString(),
            $node instanceof Node\Param => self::parameterDescription($node),
            default => 'source construct at line ' . $node->getStartLine(),
        };
    }

    private static function parameterDescription(Node\Param $parameter): string
    {
        $name = $parameter->var instanceof Node\Expr\Variable && \is_string($parameter->var->name)
            ? $parameter->var->name
            : '';

        return 'parameter $' . $name;
    }
}
