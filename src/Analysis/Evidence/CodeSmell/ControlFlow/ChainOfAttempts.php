<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell\ControlFlow;

use PhpParser\Node;
use PhpParser\Node\Stmt;

/**
 * Recognizes a foreach that tries each item until one succeeds: the one shape whose empty catch
 * means "try the next item" rather than "ignore the failure".
 *
 * A try qualifies only as a direct statement of the foreach body that can end the search on
 * success: it holds a `return` or `break`, or a `continue` that skips statements following the try — at its
 * top level or inside its `if` branches. The exit must follow work in the try. A `continue` with nothing after the try skips nothing,
 * so such a catch swallows every failure of the loop. A direct top-level `if` whose only then-statement is a bare
 * `continue` is an early guard when later work follows in the same try. Guards in sibling or nested branches and other
 * exit shapes retain the attempt exemption.
 */
final class ChainOfAttempts
{
    public function __construct(private readonly AttemptWork $work = new AttemptWork()) {}

    /** @return list<Stmt\TryCatch> */
    public function attempts(Stmt\Foreach_ $loop): array
    {
        $attempts = [];
        $statements = $this->withoutNops($loop->stmts);
        foreach ($statements as $index => $statement) {
            if ($statement instanceof Stmt\TryCatch && $this->holdsExit($statement->stmts, $statements[$index + 1] ?? null)) {
                $attempts[] = $statement;
            }
        }

        return $attempts;
    }

    /**
     * @param array<Stmt> $statements
     */
    private function holdsExit(
        array $statements,
        ?Stmt $fallback,
        ?Node $priorWork = null,
        int $nesting = 0,
    ): bool {
        $statements = $this->withoutNops($statements);
        $lastStatement = $this->lastStatement($statements);
        $laterWork = $this->laterWorkForNesting($statements, $nesting);
        foreach ($statements as $index => $statement) {
            if ($this->statementHoldsExit($statement, $lastStatement, $fallback, $priorWork, $nesting, $laterWork[$index])) {
                return true;
            }
            $priorWork = $this->nextPriorWork($statement, $priorWork);
        }

        return false;
    }

    private function statementHoldsExit(
        Stmt $statement,
        ?Stmt $lastStatement,
        ?Stmt $fallback,
        ?Node $priorWork,
        int $nesting,
        ?Node $laterWork,
    ): bool {
        if ($this->endsAttemptAfterWork($statement, $fallback, $priorWork)) {
            return true;
        }

        if ($nesting === 0 && $statement instanceof Stmt\If_ && $this->isDirectEarlyContinueGuard($statement, $laterWork)) {
            return $this->otherBranchesHoldExit($statement, $fallback, $priorWork);
        }

        return $this->ifBranchHoldsExit($statement, $lastStatement, $fallback, $priorWork);
    }

    private function nextPriorWork(Stmt $statement, ?Node $priorWork): ?Node
    {
        if ($statement instanceof Stmt\Expression && $this->work->in($statement->expr)) {
            return $statement;
        }

        return $priorWork;
    }

    private function ifBranchHoldsExit(
        Stmt $statement,
        ?Stmt $lastStatement,
        ?Stmt $fallback,
        ?Node $priorWork,
    ): bool {
        if (!$statement instanceof Stmt\If_) {
            return false;
        }

        $branchWork = $priorWork;
        if ($branchWork === null && $statement === $lastStatement && $this->work->in($statement->cond)) {
            $branchWork = $statement->cond;
        }

        return $this->branchHoldsExit($statement, $fallback, $branchWork);
    }

    private function endsAttemptAfterWork(Stmt $statement, ?Stmt $fallback, ?Node $priorWork): bool
    {
        if ($statement instanceof Stmt\Return_) {
            return $priorWork !== null || ($statement->expr !== null && $this->work->in($statement->expr));
        }

        return $priorWork !== null && ($statement instanceof Stmt\Break_ || ($fallback !== null && $statement instanceof Stmt\Continue_));
    }

    private function branchHoldsExit(Stmt\If_ $if, ?Stmt $fallback, ?Node $priorWork): bool
    {
        $branches = [$if->stmts, ...array_map(static fn(Stmt\ElseIf_ $elseif): array => $elseif->stmts, $if->elseifs)];
        if ($if->else !== null) {
            $branches[] = $if->else->stmts;
        }

        foreach ($branches as $branch) {
            if ($this->holdsExit($branch, $fallback, $priorWork, 1)) {
                return true;
            }
        }

        return false;
    }

    private function isDirectEarlyContinueGuard(Stmt\If_ $statement, ?Node $laterWork): bool
    {
        if ($laterWork === null) {
            return false;
        }

        return \count($statement->stmts) === 1
            && $statement->stmts[0] instanceof Stmt\Continue_
            && $statement->stmts[0]->num === null;
    }

    private function otherBranchesHoldExit(Stmt\If_ $if, ?Stmt $fallback, ?Node $priorWork): bool
    {
        $branches = array_map(static fn(Stmt\ElseIf_ $elseif): array => $elseif->stmts, $if->elseifs);
        if ($if->else !== null) {
            $branches[] = $if->else->stmts;
        }

        foreach ($branches as $branch) {
            if ($this->holdsExit($branch, $fallback, $priorWork, 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Stmt> $statements
     *
     * @return list<?Node>
     */
    private function laterWorkForNesting(array $statements, int $nesting): array
    {
        if ($nesting !== 0) {
            return array_fill(0, \count($statements), null);
        }

        return $this->laterWorkByStatement($statements);
    }

    /** @param list<Stmt> $statements */
    private function lastStatement(array $statements): ?Stmt
    {
        return $statements[array_key_last($statements)] ?? null;
    }

    /**
     * @param list<Stmt> $statements
     *
     * @return list<?Node>
     */
    private function laterWorkByStatement(array $statements): array
    {
        $laterWork = [];
        $nextWork = null;
        for ($index = \count($statements) - 1; $index >= 0; --$index) {
            $laterWork[$index] = $nextWork;
            if ($this->work->inNode($statements[$index])) {
                $nextWork = $statements[$index];
            }
        }

        ksort($laterWork);

        return array_values($laterWork);
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
