<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell\RepeatedExpression;

use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Evaluates repeated branch, match-arm, and switch-case conditions.
 *
 * Conditions are compared structurally, calls included: unlike binary operands and ternary
 * branches, a repeated condition is not excused for having side effects.
 */
final class RepeatedConditions
{
    public function __construct(
        private readonly RepeatedExpressions $expressions = new RepeatedExpressions(),
        private readonly IfChain $ifChain = new IfChain(),
    ) {}

    /** @return list<IdenticalSubExpressionFinding> */
    public function findings(Stmt\If_|Expr\Match_|Stmt\Switch_ $node, string $subjectId): array
    {
        $conditions = match (true) {
            $node instanceof Stmt\If_ => $this->ifConditions($node),
            $node instanceof Expr\Match_ => $this->matchConditions($node),
            default => $this->switchConditions($node),
        };
        $type = $node instanceof Stmt\If_ ? 'duplicate_condition' : ($node instanceof Expr\Match_ ? 'duplicate_match_arm' : 'duplicate_switch_case');
        $findings = [];
        foreach ($conditions as $index => $condition) {
            for ($previous = 0; $previous < $index; ++$previous) {
                if ($this->expressions->areEqual($condition['expr'], $conditions[$previous]['expr'])) {
                    $findings[] = new IdenticalSubExpressionFinding($type, $condition['line'], '', $subjectId);
                    break;
                }
            }
        }

        return $findings;
    }

    /** @return list<array{expr: Expr, line: int}> */
    private function ifConditions(Stmt\If_ $node): array
    {
        $items = [];
        foreach ([$node, ...$this->ifChain->continuations($node)] as $link) {
            $items[] = ['expr' => $link->cond, 'line' => $link->cond->getStartLine()];
            foreach ($link->elseifs as $elseif) {
                $items[] = ['expr' => $elseif->cond, 'line' => $elseif->cond->getStartLine()];
            }
        }

        return $items;
    }

    /** @return list<array{expr: Expr, line: int}> */
    private function matchConditions(Expr\Match_ $node): array
    {
        $items = [];
        foreach ($node->arms as $arm) {
            foreach ($arm->conds ?? [] as $condition) {
                $items[] = ['expr' => $condition, 'line' => $condition->getStartLine()];
            }
        } return $items;
    }
    /** @return list<array{expr: Expr, line: int}> */
    private function switchConditions(Stmt\Switch_ $node): array
    {
        $items = [];
        foreach ($node->cases as $case) {
            if ($case->cond !== null) {
                $items[] = ['expr' => $case->cond, 'line' => $case->cond->getStartLine()];
            }
        } return $items;
    }
}
