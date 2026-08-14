<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell\RepeatedExpression;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\Ternary;

/** Evaluates repeated binary and ternary expressions and structural equality. */
final class RepeatedExpressions
{
    /** @var list<string> */
    private const SUSPICIOUS_SIGILS = [
        '===', '==', '!==', '!=', '>', '<', '>=', '<=', '<=>', '&&', '||',
        'and', 'or', 'xor', '-', '/', '%', '^', '??',
    ];

    /** @return list<IdenticalSubExpressionFinding> */
    public function findings(BinaryOp|Ternary $node, string $subjectId): array
    {
        return $node instanceof BinaryOp
            ? $this->binaryFinding($node, $subjectId)
            : $this->ternaryFinding($node, $subjectId);
    }

    public function areEqual(mixed $left, mixed $right): bool
    {
        $pending = [[$left, $right]];
        while ($pending !== []) {
            [$currentLeft, $currentRight] = array_pop($pending);
            if ($currentLeft === $currentRight) {
                continue;
            }

            if (\is_array($currentLeft) && \is_array($currentRight)) {
                if (!$this->arraysAreEqual($pending, $currentLeft, $currentRight)) {
                    return false;
                }

                continue;
            }

            if (!$currentLeft instanceof Node || !$currentRight instanceof Node || $currentLeft::class !== $currentRight::class) {
                return false;
            }

            $this->appendNodePairs($pending, $currentLeft, $currentRight);
        }

        return true;
    }

    /** @return list<IdenticalSubExpressionFinding> */
    private function binaryFinding(BinaryOp $node, string $subjectId): array
    {
        $sigil = $node->getOperatorSigil();
        if (!\in_array($sigil, self::SUSPICIOUS_SIGILS, true)
            || $this->hasSideEffects($node->left)
            || $this->hasSideEffects($node->right)
            || !$this->areEqual($node->left, $node->right)) {
            return [];
        }

        return [new IdenticalSubExpressionFinding('identical_operands', $node->getStartLine(), '... ' . $sigil . ' ...', $subjectId)];
    }

    /** @return list<IdenticalSubExpressionFinding> */
    private function ternaryFinding(Ternary $node, string $subjectId): array
    {
        $firstBranch = $node->if ?? $node->cond;
        if ($this->hasSideEffects($firstBranch)
            || $this->hasSideEffects($node->else)
            || !$this->areEqual($firstBranch, $node->else)) {
            return [];
        }

        $detail = $node->if === null ? '... ?: ...' : '... ? ... : ...';

        return [new IdenticalSubExpressionFinding('identical_ternary', $node->getStartLine(), $detail, $subjectId)];
    }

    private function hasSideEffects(Node $node): bool
    {
        $pending = [$node];
        while ($pending !== []) {
            $current = array_pop($pending);
            if ($this->isSideEffect($current)) {
                return true;
            }

            array_push($pending, ...$this->children($current));
        }

        return false;
    }

    private function isSideEffect(Node $node): bool
    {
        $type = $node->getType();

        return str_starts_with($type, 'Expr_AssignOp_') || \in_array($type, [
            'Expr_FuncCall', 'Expr_MethodCall', 'Expr_StaticCall', 'Expr_NullsafeMethodCall',
            'Expr_New', 'Expr_Yield', 'Expr_YieldFrom', 'Expr_PreInc', 'Expr_PreDec',
            'Expr_PostInc', 'Expr_PostDec', 'Expr_Assign', 'Expr_AssignOp', 'Expr_AssignRef',
            'Expr_ShellExec', 'Expr_Eval', 'Expr_Exit', 'Expr_Print', 'Expr_Include', 'Expr_Throw',
        ], true);
    }

    /**
     * @param array<array{mixed, mixed}> $pending
     * @param array<array-key, mixed> $left
     * @param array<array-key, mixed> $right
     */
    private function arraysAreEqual(array &$pending, array $left, array $right): bool
    {
        return \count($left) === \count($right) && $this->appendArrayPairs($pending, $left, $right);
    }

    /**
     * @param array<array{mixed, mixed}> $pending
     * @param array<array-key, mixed> $left
     * @param array<array-key, mixed> $right
     */
    private function appendArrayPairs(array &$pending, array $left, array $right): bool
    {
        foreach ($left as $key => $value) {
            if (!\array_key_exists($key, $right)) {
                return false;
            }

            $pending[] = [$value, $right[$key]];
        }

        return true;
    }

    /** @param array<array{mixed, mixed}> $pending */
    private function appendNodePairs(array &$pending, Node $left, Node $right): void
    {
        $leftProperties = get_object_vars($left);
        $rightProperties = get_object_vars($right);
        foreach ($left->getSubNodeNames() as $name) {
            $pending[] = [$leftProperties[$name] ?? null, $rightProperties[$name] ?? null];
        }
    }

    /** @return list<Node> */
    private function children(Node $node): array
    {
        $children = [];
        $properties = get_object_vars($node);
        foreach ($node->getSubNodeNames() as $name) {
            $value = $properties[$name] ?? null;
            foreach (\is_array($value) ? $value : [$value] as $item) {
                if ($item instanceof Node) {
                    $children[] = $item;
                }
            }
        }

        return $children;
    }
}
