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
 * so such a catch swallows every failure of the loop.
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
        ?Node $loopExitWork = null,
    ): bool {
        $statements = $this->withoutNops($statements);
        $lastStatement = $statements[array_key_last($statements)] ?? null;
        $laterWork = $this->laterWorkByStatement($statements);
        foreach ($statements as $index => $statement) {
            if ($this->endsAttemptAfterWork($statement, $fallback, $priorWork, $loopExitWork)) {
                return true;
            }
            if ($this->ifBranchHoldsExit(
                $statement,
                $lastStatement,
                $fallback,
                $priorWork,
                $loopExitWork,
                $laterWork[$index],
            )) {
                return true;
            }
            if ($statement instanceof Stmt\Expression && $this->work->in($statement->expr)) {
                $priorWork = $statement;
                $loopExitWork = $statement;
            }
        }

        return false;
    }

    private function ifBranchHoldsExit(
        Stmt $statement,
        ?Stmt $lastStatement,
        ?Stmt $fallback,
        ?Node $priorWork,
        ?Node $loopExitWork,
        ?Node $laterWork,
    ): bool {
        if (!$statement instanceof Stmt\If_) {
            return false;
        }

        $branchWork = $priorWork;
        $branchLoopExitWork = $loopExitWork;
        if ($statement === $lastStatement && $this->work->in($statement->cond)) {
            $branchWork ??= $statement->cond;
            $branchLoopExitWork = $statement->cond;
        }

        if ($laterWork !== null) {
            $branchLoopExitWork = null;
        }

        return $this->branchHoldsExit($statement, $fallback, $branchWork, $branchLoopExitWork);
    }

    private function endsAttemptAfterWork(
        Stmt $statement,
        ?Stmt $fallback,
        ?Node $priorWork,
        ?Node $loopExitWork,
    ): bool {
        if ($statement instanceof Stmt\Return_) {
            return $priorWork !== null || ($statement->expr !== null && $this->work->in($statement->expr));
        }

        return $loopExitWork !== null && ($statement instanceof Stmt\Break_ || ($fallback !== null && $statement instanceof Stmt\Continue_));
    }

    private function branchHoldsExit(
        Stmt\If_ $if,
        ?Stmt $fallback,
        ?Node $priorWork,
        ?Node $loopExitWork,
    ): bool {
        $branches = [$if->stmts, ...array_map(static fn(Stmt\ElseIf_ $elseif): array => $elseif->stmts, $if->elseifs)];
        if ($if->else !== null) {
            $branches[] = $if->else->stmts;
        }

        foreach ($branches as $branch) {
            if ($this->holdsExit($branch, $fallback, $priorWork, $loopExitWork)) {
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
