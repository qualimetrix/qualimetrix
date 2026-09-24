<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell\RepeatedExpression;

use PhpParser\Node\Stmt;

/**
 * The shape of an if chain: an else holding nothing but another if (`else if`, `else { if }`)
 * continues the chain of the outer if, exactly as an elseif does.
 */
final class IfChain
{
    /**
     * The ifs that continue the chain headed by $head, outermost first. They belong to the chain
     * of their head and must not be evaluated as chains of their own.
     *
     * @return list<Stmt\If_>
     */
    public function continuations(Stmt\If_ $head): array
    {
        $continuations = [];
        for ($link = $head; ($next = $this->next($link)) !== null; $link = $next) {
            $continuations[] = $next;
        }

        return $continuations;
    }

    private function next(Stmt\If_ $link): ?Stmt\If_
    {
        $statements = array_values(array_filter($link->else->stmts ?? [], static fn(Stmt $statement): bool => !$statement instanceof Stmt\Nop));

        return \count($statements) === 1 && $statements[0] instanceof Stmt\If_ ? $statements[0] : null;
    }
}
