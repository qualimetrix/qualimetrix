<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Complexity;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Stmt\Class_;

/**
 * Calculates the zero-based NPath contribution of an expression tree.
 *
 * Leaves contribute zero. Transparent expression wrappers contribute the sum
 * of their expression-bearing children. Path-producing expressions add their
 * own branch contribution. Nested callable and anonymous-class bodies belong
 * to separate analysis scopes and are deliberately opaque.
 */
final class NpathExpressionCalculator
{
    public const int MAX_NPATH = 1_000_000_000;

    public function calculate(Expr $expr): int
    {
        return match (true) {
            $expr instanceof Closure, $expr instanceof ArrowFunction => 0,
            $expr instanceof Ternary => $this->calculateTernary($expr),
            $expr instanceof BinaryOp\BooleanAnd,
            $expr instanceof BinaryOp\LogicalAnd,
            $expr instanceof BinaryOp\BooleanOr,
            $expr instanceof BinaryOp\LogicalOr,
            $expr instanceof BinaryOp\Coalesce => $this->saturatingAdd(
                1,
                $this->calculate($expr->left),
                $this->calculate($expr->right),
            ),
            $expr instanceof Match_ => $this->calculateMatch($expr),
            $expr instanceof NullsafeMethodCall,
            $expr instanceof NullsafePropertyFetch => $this->saturatingAdd(
                1,
                $this->calculateTransparentChildren($expr),
            ),
            $expr instanceof New_ => $this->calculateNew($expr),
            default => $this->calculateTransparentChildren($expr),
        };
    }

    public function saturatingAdd(int ...$values): int
    {
        $sum = 0;

        foreach ($values as $value) {
            if ($value >= self::MAX_NPATH - $sum) {
                return self::MAX_NPATH;
            }

            $sum += $value;
        }

        return $sum;
    }

    public function saturatingMultiply(int $left, int $right): int
    {
        if ($left === 0 || $right === 0) {
            return 0;
        }

        if ($left >= self::MAX_NPATH || $right >= self::MAX_NPATH) {
            return self::MAX_NPATH;
        }

        if ($left > intdiv(self::MAX_NPATH, $right)) {
            return self::MAX_NPATH;
        }

        return $left * $right;
    }

    private function calculateTernary(Ternary $ternary): int
    {
        return $this->saturatingAdd(
            2,
            $this->calculate($ternary->cond),
            $ternary->if !== null ? $this->calculate($ternary->if) : 0,
            $this->calculate($ternary->else),
        );
    }

    private function calculateMatch(Match_ $match): int
    {
        $npath = $this->calculate($match->cond);

        foreach ($match->arms as $arm) {
            foreach ($arm->conds ?? [] as $condition) {
                $npath = $this->saturatingAdd($npath, $this->calculate($condition));
            }

            $npath = $this->saturatingAdd($npath, max(1, $this->calculate($arm->body)));
        }

        return $npath;
    }

    private function calculateNew(New_ $new): int
    {
        $npath = $new->class instanceof Expr ? $this->calculate($new->class) : 0;

        foreach ($new->args as $arg) {
            $npath = $this->saturatingAdd($npath, $this->calculateNode($arg));
        }

        return $npath;
    }

    private function calculateTransparentChildren(Node $node): int
    {
        $npath = 0;
        $properties = get_object_vars($node);

        foreach ($node->getSubNodeNames() as $name) {
            $npath = $this->saturatingAdd($npath, $this->calculateNode($properties[$name] ?? null));
        }

        return $npath;
    }

    private function calculateNode(mixed $value): int
    {
        if ($value instanceof Closure || $value instanceof ArrowFunction || $value instanceof Class_) {
            return 0;
        }

        if ($value instanceof Expr) {
            return $this->calculate($value);
        }

        if ($value instanceof Node) {
            return $this->calculateTransparentChildren($value);
        }

        if (!\is_array($value)) {
            return 0;
        }

        $npath = 0;

        foreach ($value as $item) {
            $npath = $this->saturatingAdd($npath, $this->calculateNode($item));
        }

        return $npath;
    }
}
