<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Complexity;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\AssignOp;
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
 * own branch contribution. A nullsafe access contributes one branch here; the
 * enclosing callable statement contributes its base path in the visitor.
 * Nested callable and anonymous-class bodies belong to separate analysis
 * scopes and are deliberately opaque.
 *
 * @qmx-threshold complexity.wmc warning=52 error=52 -- Finite php-parser expression algebra keeps exact NPath composition and saturation together (grew by one arm for the `??=` assignment-coalesce operator).
 */
final class NpathExpressionCalculator
{
    public const int MAX_NPATH = 1_000_000_000;

    public function calculate(Expr $expr): int
    {
        $contributions = $this->calculateContributions($expr);

        return $this->saturatingAdd($contributions['ordinary'], $contributions['nullsafe']);
    }

    /**
     * Separates non-nullsafe expression paths from nullsafe branch paths.
     *
     * The visitor uses this at callable-expression boundaries to add exactly
     * one statement base while keeping the public {@see calculate()} total
     * contribution unchanged for structural formulas.
     *
     * @return array{ordinary: int, nullsafe: int}
     *
     * @qmx-threshold complexity.cyclomatic warning=15 error=15 -- Finite php-parser expression dispatch preserves the closed NPath contribution algebra (grew by one arm for the `??=` assignment-coalesce operator).
     */
    public function calculateContributions(Expr $expr): array
    {
        return match (true) {
            $expr instanceof Closure, $expr instanceof ArrowFunction => $this->emptyContributions(),
            $expr instanceof Ternary => $this->calculateTernaryContributions($expr),
            $expr instanceof BinaryOp\BooleanAnd,
            $expr instanceof BinaryOp\LogicalAnd,
            $expr instanceof BinaryOp\BooleanOr,
            $expr instanceof BinaryOp\LogicalOr,
            $expr instanceof BinaryOp\Coalesce => $this->addContributions(
                $this->ordinaryContribution(1),
                $this->calculateContributions($expr->left),
                $this->calculateContributions($expr->right),
            ),
            $expr instanceof AssignOp\Coalesce => $this->addContributions(
                $this->ordinaryContribution(1),
                $this->calculateContributions($expr->var),
                $this->calculateContributions($expr->expr),
            ),
            $expr instanceof Match_ => $this->calculateMatchContributions($expr),
            $expr instanceof NullsafeMethodCall,
            $expr instanceof NullsafePropertyFetch => $this->addContributions(
                $this->nullsafeContribution(1),
                $this->calculateTransparentChildrenContributions($expr),
            ),
            $expr instanceof New_ => $this->calculateNewContributions($expr),
            default => $this->calculateTransparentChildrenContributions($expr),
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

    /** @return array{ordinary: int, nullsafe: int} */
    private function calculateTernaryContributions(Ternary $ternary): array
    {
        return $this->addContributions(
            $this->ordinaryContribution(2),
            $this->calculateContributions($ternary->cond),
            $ternary->if !== null ? $this->calculateContributions($ternary->if) : $this->emptyContributions(),
            $this->calculateContributions($ternary->else),
        );
    }

    /** @return array{ordinary: int, nullsafe: int} */
    private function calculateMatchContributions(Match_ $match): array
    {
        $contributions = $this->calculateContributions($match->cond);

        foreach ($match->arms as $arm) {
            foreach ($arm->conds ?? [] as $condition) {
                $contributions = $this->addContributions($contributions, $this->calculateContributions($condition));
            }

            $bodyContributions = $this->calculateContributions($arm->body);
            if ($bodyContributions['ordinary'] === 0 && $bodyContributions['nullsafe'] === 0) {
                $bodyContributions = $this->ordinaryContribution(1);
            }

            $contributions = $this->addContributions($contributions, $bodyContributions);
        }

        return $contributions;
    }

    /** @return array{ordinary: int, nullsafe: int} */
    private function calculateNewContributions(New_ $new): array
    {
        $contributions = $new->class instanceof Expr
            ? $this->calculateContributions($new->class)
            : $this->emptyContributions();

        foreach ($new->args as $arg) {
            $contributions = $this->addContributions($contributions, $this->calculateNodeContributions($arg));
        }

        return $contributions;
    }

    /** @return array{ordinary: int, nullsafe: int} */
    private function calculateTransparentChildrenContributions(Node $node): array
    {
        $contributions = $this->emptyContributions();
        $properties = get_object_vars($node);

        foreach ($node->getSubNodeNames() as $name) {
            $contributions = $this->addContributions(
                $contributions,
                $this->calculateNodeContributions($properties[$name] ?? null),
            );
        }

        return $contributions;
    }

    /** @return array{ordinary: int, nullsafe: int} */
    private function calculateNodeContributions(mixed $value): array
    {
        if ($value instanceof Closure || $value instanceof ArrowFunction || $value instanceof Class_) {
            return $this->emptyContributions();
        }

        if ($value instanceof Expr) {
            return $this->calculateContributions($value);
        }

        if ($value instanceof Node) {
            return $this->calculateTransparentChildrenContributions($value);
        }

        if (!\is_array($value)) {
            return $this->emptyContributions();
        }

        $contributions = $this->emptyContributions();

        foreach ($value as $item) {
            $contributions = $this->addContributions($contributions, $this->calculateNodeContributions($item));
        }

        return $contributions;
    }

    /** @return array{ordinary: int, nullsafe: int} */
    private function emptyContributions(): array
    {
        return ['ordinary' => 0, 'nullsafe' => 0];
    }

    /** @return array{ordinary: int, nullsafe: int} */
    private function ordinaryContribution(int $value): array
    {
        return ['ordinary' => $value, 'nullsafe' => 0];
    }

    /** @return array{ordinary: int, nullsafe: int} */
    private function nullsafeContribution(int $value): array
    {
        return ['ordinary' => 0, 'nullsafe' => $value];
    }

    /**
     * @param array{ordinary: int, nullsafe: int} ...$contributions
     *
     * @return array{ordinary: int, nullsafe: int}
     */
    private function addContributions(array ...$contributions): array
    {
        $ordinary = 0;
        $nullsafe = 0;

        foreach ($contributions as $contribution) {
            $ordinary = $this->saturatingAdd($ordinary, $contribution['ordinary']);
            $nullsafe = $this->saturatingAdd($nullsafe, $contribution['nullsafe']);
        }

        return ['ordinary' => $ordinary, 'nullsafe' => $nullsafe];
    }
}
