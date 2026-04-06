<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\CodeSmell;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\If_;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Metrics\ResettableVisitorInterface;

/**
 * Detects identical sub-expressions that indicate copy-paste errors or logic bugs.
 *
 * Detection types:
 * - identical_operands: $a === $a, $a - $a, $a && $a, etc.
 * - duplicate_condition: same condition in if/elseif chain
 * - identical_ternary: $cond ? $expr : $expr (including short ternary $a ?: $a)
 * - duplicate_match_arm: same condition in match expression
 * - duplicate_switch_case: same value in switch/case
 */
final class IdenticalSubExpressionVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    /** @var list<IdenticalSubExpressionFinding> */
    private array $findings = [];

    /**
     * Binary operators where identical operands are always suspicious.
     *
     * Excluded by design: +, *, . (legitimate doubling), &, | (idempotent but common), <<, >> (shifts).
     *
     * @var list<class-string<BinaryOp>>
     */
    private const SUSPICIOUS_BINARY_OPS = [
        // Comparison (always true/false with identical pure operands)
        BinaryOp\Identical::class,
        BinaryOp\Equal::class,
        BinaryOp\NotIdentical::class,
        BinaryOp\NotEqual::class,
        BinaryOp\Greater::class,
        BinaryOp\Smaller::class,
        BinaryOp\GreaterOrEqual::class,
        BinaryOp\SmallerOrEqual::class,
        BinaryOp\Spaceship::class,
        // Logical (redundant with identical pure operands)
        BinaryOp\BooleanAnd::class,
        BinaryOp\BooleanOr::class,
        BinaryOp\LogicalAnd::class,
        BinaryOp\LogicalOr::class,
        BinaryOp\LogicalXor::class,
        // Arithmetic (always 0 or division by zero)
        BinaryOp\Minus::class,
        BinaryOp\Div::class,
        BinaryOp\Mod::class,
        // Bitwise (always 0 for XOR)
        BinaryOp\BitwiseXor::class,
        // Null coalesce (pointless with identical operands)
        BinaryOp\Coalesce::class,
    ];

    public function reset(): void
    {
        $this->findings = [];
    }

    /**
     * @return list<IdenticalSubExpressionFinding>
     */
    public function getFindings(): array
    {
        return $this->findings;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof BinaryOp) {
            $this->checkIdenticalOperands($node);
        }

        if ($node instanceof Ternary) {
            $this->checkIdenticalTernaryBranches($node);
        }

        if ($node instanceof If_) {
            $this->checkDuplicateConditions($node);
        }

        if ($node instanceof Expr\Match_) {
            $this->checkDuplicateMatchArms($node);
        }

        if ($node instanceof Stmt\Switch_) {
            $this->checkDuplicateSwitchCases($node);
        }

        return null;
    }

    private function checkIdenticalOperands(BinaryOp $node): void
    {
        $class = $node::class;

        if (!\in_array($class, self::SUSPICIOUS_BINARY_OPS, true)) {
            return;
        }

        if ($this->hasSideEffects($node->left) || $this->hasSideEffects($node->right)) {
            return;
        }

        if (!$this->nodesEqual($node->left, $node->right)) {
            return;
        }

        $operator = $this->getOperatorSymbol($node);

        $this->findings[] = new IdenticalSubExpressionFinding(
            type: 'identical_operands',
            line: $node->getStartLine(),
            detail: '... ' . $operator . ' ...',
        );
    }

    private function checkIdenticalTernaryBranches(Ternary $node): void
    {
        // Short ternary ($a ?: $a) — compare condition with else branch
        if ($node->if === null) {
            if ($this->hasSideEffects($node->cond) || $this->hasSideEffects($node->else)) {
                return;
            }

            if ($this->nodesEqual($node->cond, $node->else)) {
                $this->findings[] = new IdenticalSubExpressionFinding(
                    type: 'identical_ternary',
                    line: $node->getStartLine(),
                    detail: '... ?: ...',
                );
            }

            return;
        }

        if ($this->hasSideEffects($node->if) || $this->hasSideEffects($node->else)) {
            return;
        }

        if (!$this->nodesEqual($node->if, $node->else)) {
            return;
        }

        $this->findings[] = new IdenticalSubExpressionFinding(
            type: 'identical_ternary',
            line: $node->getStartLine(),
            detail: '... ? ... : ...',
        );
    }

    private function checkDuplicateConditions(If_ $node): void
    {
        if ($node->elseifs === []) {
            return;
        }

        /** @var list<array{expr: Expr, line: int}> */
        $conditions = [
            ['expr' => $node->cond, 'line' => $node->cond->getStartLine()],
        ];

        foreach ($node->elseifs as $elseif) {
            $conditions[] = ['expr' => $elseif->cond, 'line' => $elseif->cond->getStartLine()];
        }

        $this->reportDuplicateExpressions($conditions, 'duplicate_condition');
    }

    private function checkDuplicateMatchArms(Expr\Match_ $node): void
    {
        /** @var list<array{expr: Expr, line: int}> */
        $conditions = [];

        foreach ($node->arms as $arm) {
            if ($arm->conds === null) {
                continue;
            }

            foreach ($arm->conds as $cond) {
                $conditions[] = ['expr' => $cond, 'line' => $cond->getStartLine()];
            }
        }

        $this->reportDuplicateExpressions($conditions, 'duplicate_match_arm');
    }

    private function checkDuplicateSwitchCases(Stmt\Switch_ $node): void
    {
        /** @var list<array{expr: Expr, line: int}> */
        $conditions = [];

        foreach ($node->cases as $case) {
            if ($case->cond === null) {
                // default case — skip
                continue;
            }

            $conditions[] = ['expr' => $case->cond, 'line' => $case->cond->getStartLine()];
        }

        $this->reportDuplicateExpressions($conditions, 'duplicate_switch_case');
    }

    /**
     * Reports duplicate expressions from a list of condition/value pairs.
     *
     * @param list<array{expr: Expr, line: int}> $conditions
     */
    private function reportDuplicateExpressions(array $conditions, string $findingType): void
    {
        foreach ($conditions as $i => $condition) {
            for ($j = 0; $j < $i; $j++) {
                if ($this->nodesEqual($condition['expr'], $conditions[$j]['expr'])) {
                    $this->findings[] = new IdenticalSubExpressionFinding(
                        type: $findingType,
                        line: $condition['line'],
                        detail: '',
                    );

                    break; // One report per duplicate is enough
                }
            }
        }
    }

    /**
     * Recursively compares two AST nodes for structural equality.
     * Ignores attributes (line numbers, comments) — only compares structure.
     */
    private function nodesEqual(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }

        if (\is_array($a) && \is_array($b)) {
            if (\count($a) !== \count($b)) {
                return false;
            }

            foreach ($a as $k => $v) {
                if (!\array_key_exists($k, $b) || !$this->nodesEqual($v, $b[$k])) {
                    return false;
                }
            }

            return true;
        }

        if ($a instanceof Node && $b instanceof Node) {
            if ($a::class !== $b::class) {
                return false;
            }

            foreach ($a->getSubNodeNames() as $name) {
                // @phpstan-ignore-next-line property.dynamicName
                if (!$this->nodesEqual($a->{$name}, $b->{$name})) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Checks if an expression has side effects (function calls, assignments, increments, I/O, etc.).
     * Expressions with side effects are excluded from duplicate detection to avoid false positives.
     */
    private function hasSideEffects(Node $node): bool
    {
        if (
            $node instanceof Expr\FuncCall
            || $node instanceof Expr\MethodCall
            || $node instanceof Expr\StaticCall
            || $node instanceof Expr\NullsafeMethodCall
            || $node instanceof Expr\New_
            || $node instanceof Expr\Yield_
            || $node instanceof Expr\YieldFrom
            || $node instanceof Expr\PreInc
            || $node instanceof Expr\PreDec
            || $node instanceof Expr\PostInc
            || $node instanceof Expr\PostDec
            || $node instanceof Expr\Assign
            || $node instanceof Expr\AssignOp
            || $node instanceof Expr\AssignRef
            || $node instanceof Expr\ShellExec
            || $node instanceof Expr\Eval_
            || $node instanceof Expr\Exit_
            || $node instanceof Expr\Print_
            || $node instanceof Expr\Include_
            || $node instanceof Expr\Throw_
        ) {
            return true;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $sub = $node->{$name}; // @phpstan-ignore property.dynamicName

            if ($sub instanceof Node && $this->hasSideEffects($sub)) {
                return true;
            }

            if (\is_array($sub)) {
                foreach ($sub as $item) {
                    if ($item instanceof Node && $this->hasSideEffects($item)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function getOperatorSymbol(BinaryOp $node): string
    {
        return match ($node::class) {
            BinaryOp\Identical::class => '===',
            BinaryOp\Equal::class => '==',
            BinaryOp\NotIdentical::class => '!==',
            BinaryOp\NotEqual::class => '!=',
            BinaryOp\Greater::class => '>',
            BinaryOp\Smaller::class => '<',
            BinaryOp\GreaterOrEqual::class => '>=',
            BinaryOp\SmallerOrEqual::class => '<=',
            BinaryOp\Spaceship::class => '<=>',
            BinaryOp\BooleanAnd::class => '&&',
            BinaryOp\BooleanOr::class => '||',
            BinaryOp\LogicalAnd::class => 'and',
            BinaryOp\LogicalOr::class => 'or',
            BinaryOp\LogicalXor::class => 'xor',
            BinaryOp\Minus::class => '-',
            BinaryOp\Div::class => '/',
            BinaryOp\Mod::class => '%',
            BinaryOp\BitwiseXor::class => '^',
            BinaryOp\Coalesce::class => '??',
            default => '?',
        };
    }
}
