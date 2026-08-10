<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Size;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Core\Metric\CallableWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Metrics\ResettableVisitorInterface;
use Qualimetrix\Metrics\VisitorCallableScope;
use Qualimetrix\Metrics\VisitorMethodTrackingTrait;

/**
 * Counts semantic statements owned by each method, function, or closure.
 *
 * Counted nodes are executable statements (expression, return, echo, unset,
 * global/static declarations, break/continue/goto/label) and control-flow
 * statements or clauses (if/elseif/else, switch/case, loops, try/catch/finally,
 * and declare). Structural declarations, empty statements, comments, and blank
 * lines are not counted. An arrow function owns one synthetic statement for its
 * expression body.
 *
 * Container statements count as one and their contained statements are counted
 * recursively. Nested named/anonymous callables own their statements; they are
 * excluded from the enclosing callable. Anonymous-class internals are ignored.
 */
final class MethodStatementCountVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    use VisitorMethodTrackingTrait;

    /** @var array<string, VisitorCallableScope> */
    private array $scopes = [];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var list<string> */
    private array $methodStack = [];

    public function reset(): void
    {
        $this->scopes = [];
        $this->counts = [];
        $this->methodStack = [];
        $this->resetVisitorMethodContext();
    }

    /**
     * @return list<CallableWithMetrics>
     */
    public function getCallablesWithMetrics(RelativePath $file): array
    {
        $result = [];

        $ordinals = $this->callableCollisionOrdinals($this->scopes);
        foreach ($this->scopes as $key => $scope) {
            $result[] = $this->createCallableWithMetrics(
                $scope,
                $file,
                (new MetricBag())->with(MetricName::SIZE_METHOD_STATEMENT_COUNT, $this->counts[$key]),
                $ordinals[$key],
            );
        }

        return $result;
    }

    /** @return array<string, int> */
    public function getStatementCounts(): array
    {
        /** @var array<string, int> $counts */
        $counts = $this->projectLogicalMetricMap($this->counts, $this->scopes);

        return $counts;
    }

    public function enterNode(Node $node): ?int
    {
        $scope = $this->enterVisitorMethodContext($node);
        if ($scope !== null) {
            $this->startMethod($scope);

            if ($node instanceof Expr\ArrowFunction) {
                $this->incrementCurrentMethod();
            }

            return null;
        }

        if ($this->isCountedStatement($node)) {
            $this->incrementCurrentMethod();
        }

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
        $this->scopes[$fqn] = $scope;
        $this->counts[$fqn] = 0;
        $this->methodStack[] = $fqn;
    }

    private function endMethod(VisitorCallableScope $scope): void
    {
        array_pop($this->methodStack);
    }

    private function incrementCurrentMethod(): void
    {
        if ($this->methodStack === []) {
            return;
        }

        $fqn = $this->methodStack[array_key_last($this->methodStack)];
        ++$this->counts[$fqn];
    }

    private function isCountedStatement(Node $node): bool
    {
        return $node instanceof Stmt\Expression
            || $node instanceof Stmt\Return_
            || $node instanceof Stmt\Echo_
            || $node instanceof Stmt\Unset_
            || $node instanceof Stmt\Global_
            || $node instanceof Stmt\Static_
            || $node instanceof Stmt\Break_
            || $node instanceof Stmt\Continue_
            || $node instanceof Stmt\Goto_
            || $node instanceof Stmt\Label
            || $node instanceof Stmt\If_
            || $node instanceof Stmt\ElseIf_
            || $node instanceof Stmt\Else_
            || $node instanceof Stmt\Switch_
            || $node instanceof Stmt\Case_
            || $node instanceof Stmt\For_
            || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\While_
            || $node instanceof Stmt\Do_
            || $node instanceof Stmt\TryCatch
            || $node instanceof Stmt\Catch_
            || $node instanceof Stmt\Finally_
            || $node instanceof Stmt\Declare_;
    }
}
