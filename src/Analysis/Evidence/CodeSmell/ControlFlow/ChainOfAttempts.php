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
 * success: it holds a `return`, or a `continue` that skips statements following the try — at its
 * top level or inside its `if` branches. A `continue` with nothing after the try skips nothing,
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
            $iterationExits = isset($statements[$index + 1])
                ? [Stmt\Return_::class, Stmt\Continue_::class]
                : [Stmt\Return_::class];
            if ($statement instanceof Stmt\TryCatch && $this->holdsExit($statement->stmts, $iterationExits)) {
                $attempts[] = $statement;
            }
        }

        return $attempts;
    }

    /**
     * @param array<Stmt> $statements
     * @param list<class-string<Stmt>> $iterationExits
     */
    private function holdsExit(array $statements, array $iterationExits): bool
    {
        foreach ($statements as $statement) {
            foreach ($iterationExits as $exit) {
                if ($statement instanceof $exit) {
                    return true;
                }
            }

            if ($statement instanceof Stmt\If_ && $this->branchHoldsExit($statement, $iterationExits)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<class-string<Stmt>> $iterationExits */
    private function branchHoldsExit(Stmt\If_ $if, array $iterationExits): bool
    {
        $branches = [$if->stmts, ...array_map(static fn(Stmt\ElseIf_ $elseif): array => $elseif->stmts, $if->elseifs)];
        if ($if->else !== null) {
            $branches[] = $if->else->stmts;
        }

        foreach ($branches as $branch) {
            if ($this->holdsExit($branch, $iterationExits)) {
                return true;
            }
        }

        return false;
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
