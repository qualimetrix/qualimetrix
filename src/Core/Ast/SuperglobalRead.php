<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Ast;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;

final readonly class SuperglobalRead
{
    private const NAMES = ['_GET', '_POST', '_REQUEST', '_COOKIE', '_SESSION', '_SERVER', '_FILES', '_ENV', 'GLOBALS'];

    private function __construct(
        public string $name,
        public Expr $node,
    ) {}

    public static function ofVariable(Variable $variable): ?string
    {
        $name = \is_string($variable->name) ? $variable->name : self::literal($variable->name);

        return $name !== null && \in_array($name, self::NAMES, true) ? $name : null;
    }

    public static function in(Expr $expression): ?self
    {
        if ($expression instanceof Variable) {
            $name = self::ofVariable($expression);

            return $name === null ? null : new self($name, $expression);
        }

        if ($expression instanceof ArrayDimFetch && $expression->var instanceof Variable && self::ofVariable($expression->var) === 'GLOBALS') {
            $name = $expression->dim === null ? null : self::literal($expression->dim);

            return $name !== null && \in_array($name, self::NAMES, true)
                ? new self($name, $expression)
                : null;
        }

        return null;
    }

    private static function literal(Expr $expression): ?string
    {
        if ($expression instanceof String_) {
            return $expression->value;
        }

        if ($expression instanceof Concat) {
            $left = self::literal($expression->left);
            $right = self::literal($expression->right);

            return $left !== null && $right !== null ? $left . $right : null;
        }

        return null;
    }
}
