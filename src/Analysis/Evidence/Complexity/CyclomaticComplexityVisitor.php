<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Complexity;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\BinaryOp\LogicalXor;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Stmt\Case_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ResettableVisitorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorCallableScope;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorMethodTrackingTrait;
use Qualimetrix\Core\Path\RelativePath;

/**
 * Visitor for calculating Cyclomatic Complexity (CC/CCN).
 *
 * CC = 1 + number of decision points
 *
 * Decision points:
 * - if, elseif, while, do-while, for, foreach: +1
 * - case (in switch): +1
 * - catch: +1
 * - && (BooleanAnd), and (LogicalAnd): +1
 * - || (BooleanOr), or (LogicalOr): +1
 * - xor (LogicalXor): +1
 * - ?: (ternary): +1
 * - ?? (null coalescing): +1
 * - ?-> (nullsafe): +1
 */
final class CyclomaticComplexityVisitor extends NodeVisitorAbstract implements DeclarationIndexAwareInterface, ResettableVisitorInterface
{
    use VisitorMethodTrackingTrait;

    /** @var array<string, int> Method/function FQN => complexity */
    private array $complexities = [];

    /** @var array<string, VisitorCallableScope> */
    private array $scopes = [];

    /** @var list<array{fqn: string, depth: int}> Stack of nested methods/functions */
    private array $methodStack = [];

    public function reset(): void
    {
        $this->complexities = [];
        $this->scopes = [];
        $this->methodStack = [];
        $this->resetVisitorMethodContext();
    }

    /**
     * @return array<string, int>
     */
    public function getComplexities(): array
    {
        /** @var array<string, int> $projected */
        $projected = $this->projectLogicalMetricMap($this->complexities, $this->scopes);

        return $projected;
    }

    /**
     * Returns structured method metrics for each analyzed method.
     *
     * @return list<CallableWithMetrics>
     */
    public function getCallablesWithMetrics(RelativePath $file): array
    {
        $result = [];

        foreach ($this->scopes as $fqn => $scope) {
            $metrics = (new MetricBag())->with('complexity.ccn', $this->complexities[$fqn] ?? 1);

            $result[] = $this->createCallableWithMetrics($scope, $file, $metrics);
        }

        return $result;
    }

    public function enterNode(Node $node): ?int
    {
        $scope = $this->enterVisitorMethodContext($node);
        if ($scope !== null) {
            $this->startMethod($scope);

            return null;
        }

        // Count decision points
        $this->countDecisionPoint($node);

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        $scope = $this->leaveVisitorMethodContext($node);
        if ($scope !== null) {
            $this->endMethod($scope);
        }

        return null;
    }

    private function startMethod(VisitorCallableScope $scope): void
    {
        $fqn = $scope->traversalKey;
        $this->methodStack[] = ['fqn' => $fqn, 'depth' => \count($this->methodStack)];
        // Initialize with base complexity of 1
        $this->complexities[$fqn] = 1;
        $this->scopes[$fqn] = $scope;
    }

    private function endMethod(VisitorCallableScope $scope): void
    {
        array_pop($this->methodStack);
    }

    private function countDecisionPoint(Node $node): void
    {
        if ($this->methodStack === []) {
            return;
        }

        $increment = $this->getDecisionPointWeight($node);

        if ($increment > 0) {
            $currentMethod = $this->methodStack[array_key_last($this->methodStack)];
            $this->complexities[$currentMethod['fqn']] += $increment;
        }
    }

    /**
     * Decision point types that always add +1 complexity.
     *
     * @var list<class-string<Node>>
     */
    private const SIMPLE_DECISION_NODES = [
        If_::class,
        ElseIf_::class,
        While_::class,
        Do_::class,
        For_::class,
        Foreach_::class,
        Catch_::class,
        BooleanAnd::class,
        LogicalAnd::class,
        BooleanOr::class,
        LogicalOr::class,
        LogicalXor::class,
        Ternary::class,
        Coalesce::class,
        NullsafeMethodCall::class,
        NullsafePropertyFetch::class,
    ];

    private function getDecisionPointWeight(Node $node): int
    {
        // Simple decision nodes: always +1
        foreach (self::SIMPLE_DECISION_NODES as $nodeClass) {
            if ($node instanceof $nodeClass) {
                return 1;
            }
        }

        // Case in switch: +1 only if has condition (not default)
        if ($node instanceof Case_ && $node->cond !== null) {
            return 1;
        }

        // Match arm: +1 for each condition value (each is a separate decision path)
        if ($node instanceof MatchArm && $node->conds !== null && $node->conds !== []) {
            return \count($node->conds);
        }

        return 0;
    }

}
