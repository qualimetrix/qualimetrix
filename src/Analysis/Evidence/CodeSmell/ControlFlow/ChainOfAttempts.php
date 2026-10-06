<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell\ControlFlow;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Recognizes a foreach that tries each item until one succeeds: the one shape whose empty catch
 * means "try the next item" rather than "ignore the failure".
 *
 * A try qualifies only as a direct statement of the foreach body that can end the search on
 * success: it holds a `return` or `break`, or a `continue` that skips statements following the try — at its
 * top level or inside its `if` branches. The exit must follow work in the try. A `continue` with nothing after the try skips nothing,
 * so such a catch swallows every failure of the loop.
 */
final class ChainOfAttempts
{
    /** @return list<Stmt\TryCatch> */
    public function attempts(Stmt\Foreach_ $loop): array
    {
        $attempts = [];
        $statements = $this->withoutNops($loop->stmts);
        foreach ($statements as $index => $statement) {
            if ($statement instanceof Stmt\TryCatch && $this->holdsExit($statement->stmts, isset($statements[$index + 1]))) {
                $attempts[] = $statement;
            }
        }

        return $attempts;
    }

    /**
     * @param array<Stmt> $statements
     */
    private function holdsExit(array $statements, bool $hasFallback, bool $worked = false): bool
    {
        $statements = $this->withoutNops($statements);
        foreach ($statements as $index => $statement) {
            if ($statement instanceof Stmt\Return_ && ($worked || ($statement->expr !== null && $this->hasWork($statement->expr)))) {
                return true;
            }
            if ($worked && ($statement instanceof Stmt\Break_ || ($hasFallback && $statement instanceof Stmt\Continue_))) {
                return true;
            }
            if ($statement instanceof Stmt\If_ && $this->branchHoldsExit($statement, $hasFallback, $worked || ($index === array_key_last($statements) && $this->hasWork($statement->cond)))) {
                return true;
            }
            $worked = $worked || $this->statementHasWork($statement);
        }

        return false;
    }

    private function branchHoldsExit(Stmt\If_ $if, bool $hasFallback, bool $worked): bool
    {
        $branches = [$if->stmts, ...array_map(static fn(Stmt\ElseIf_ $elseif): array => $elseif->stmts, $if->elseifs)];
        if ($if->else !== null) {
            $branches[] = $if->else->stmts;
        }

        foreach ($branches as $branch) {
            if ($this->holdsExit($branch, $hasFallback, $worked)) {
                return true;
            }
        }

        return false;
    }

    private function statementHasWork(Stmt $statement): bool
    {
        return $statement instanceof Stmt\Expression && $this->hasWork($statement->expr);
    }

    private function hasWork(Expr $expression): bool
    {
        return (new NodeFinder())->findFirst($expression, static fn(Node $node): bool => $node instanceof FuncCall
            || $node instanceof MethodCall
            || $node instanceof StaticCall
            || $node instanceof New_
            || $node instanceof Include_
            || $node instanceof Throw_) !== null;
    }

    /**
     * @param array<Stmt> $statements
     *
     * @return list<Stmt>
     */
    private function withoutNops(array $statements): array
    {
        return array_values(array_filter($statements, static fn(Node $statement): bool => !$statement instanceof Stmt\Nop));
    }
}
