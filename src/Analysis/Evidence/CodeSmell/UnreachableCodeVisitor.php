<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ResettableVisitorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorCallableScope;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorMethodTrackingTrait;
use Qualimetrix\Core\Path\RelativePath;

/**
 * Visitor for detecting unreachable code after terminal statements.
 *
 * The unit of analysis is one callable scope opened by the method context: a method, function,
 * closure or property hook with a statement body. Every statement list of that body is checked on
 * its own — the body itself and each list nested in if/elseif/else, loops, try/catch/finally,
 * switch cases and blocks. After a terminal statement (return, throw, exit/die, continue, break,
 * goto) the following statements of the SAME list are unreachable until a goto label.
 *
 * A dead statement is counted once, without descending into it. Nested closures, functions and
 * class declarations are not descended into: they are scopes of their own. Terminality is not
 * propagated across branches, so code after an if/else whose every branch returns is not reported.
 */
final class UnreachableCodeVisitor extends NodeVisitorAbstract implements DeclarationIndexAwareInterface, ResettableVisitorInterface
{
    use VisitorMethodTrackingTrait;

    /** @var array<string, int> Method/function FQN => unreachable statement count */
    private array $unreachableCounts = [];

    /** @var array<string, int> Method/function FQN => first unreachable line number */
    private array $firstUnreachableLines = [];

    /** @var array<string, VisitorCallableScope> */
    private array $scopes = [];

    public function reset(): void
    {
        $this->unreachableCounts = [];
        $this->firstUnreachableLines = [];
        $this->scopes = [];
        $this->resetVisitorMethodContext();
    }

    /**
     * @return array<string, int>
     */
    public function getUnreachableCounts(): array
    {
        /** @var array<string, int> $projected */
        $projected = $this->projectLogicalMetricMap($this->unreachableCounts, $this->scopes);

        return $projected;
    }

    /**
     * @return array<string, int>
     */
    public function getFirstUnreachableLines(): array
    {
        /** @var array<string, int> $projected */
        $projected = $this->projectLogicalMetricMap($this->firstUnreachableLines, $this->scopes);

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
            $bag = (new MetricBag())->with('code-smell.unreachable-code', $this->unreachableCounts[$fqn] ?? 0);

            if (isset($this->firstUnreachableLines[$fqn])) {
                $bag = $bag->with('code-smell.unreachable-code.first-line', $this->firstUnreachableLines[$fqn]);
            }

            $result[] = $this->createCallableWithMetrics($scope, $file, $bag);
        }

        return $result;
    }

    public function enterNode(Node $node): ?int
    {
        $scope = $this->enterVisitorMethodContext($node);
        if ($scope === null) {
            return null;
        }
        $this->scopes[$scope->traversalKey] = $scope;
        $statements = match (true) {
            $node instanceof Stmt\ClassMethod, $node instanceof Stmt\Function_, $node instanceof Expr\Closure => $node->stmts ?? [],
            $node instanceof Node\PropertyHook => $node->body instanceof Expr ? [] : ($node->body ?? []),
            default => [],
        };
        $this->analyzeAndStore($scope->traversalKey, $statements);

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        $this->leaveVisitorMethodContext($node);

        return null;
    }

    /**
     * @param Stmt[] $stmts
     */
    private function analyzeAndStore(string $fqn, array $stmts): void
    {
        [$count, $firstLine] = $this->analyzeStatementList($stmts);
        $this->unreachableCounts[$fqn] = $count;

        if ($firstLine !== null) {
            $this->firstUnreachableLines[$fqn] = $firstLine;
        }
    }

    /**
     * @param Stmt[] $stmts
     *
     * @return array{int, ?int} unreachable statement count and first unreachable line of the list and its nested lists
     */
    private function analyzeStatementList(array $stmts): array
    {
        [$reachable, $unreachable] = $this->partitionByReachability($stmts);
        $count = \count($unreachable);
        $lines = array_map(static fn(Stmt $stmt): int => $stmt->getStartLine(), $unreachable);

        foreach ($reachable as $stmt) {
            foreach ($this->nestedStatementLists($stmt) as $nested) {
                [$nestedCount, $nestedFirstLine] = $this->analyzeStatementList($nested);
                $count += $nestedCount;
                if ($nestedFirstLine !== null) {
                    $lines[] = $nestedFirstLine;
                }
            }
        }

        return [$count, $lines === [] ? null : min($lines)];
    }

    /**
     * Splits one statement list, without descending, into the statements that
     * can run and the dead ones.
     *
     * @param Stmt[] $stmts
     *
     * @return array{list<Stmt>, list<Stmt>}
     */
    private function partitionByReachability(array $stmts): array
    {
        $reachable = [];
        $unreachable = [];
        $terminated = false;

        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Nop) {
                continue;
            }

            // A goto label is a valid jump target — it resets reachability
            if ($terminated && !$stmt instanceof Stmt\Label) {
                $unreachable[] = $stmt;

                continue;
            }

            $reachable[] = $stmt;
            $terminated = $this->isTerminalStatement($stmt);
        }

        return [$reachable, $unreachable];
    }

    /**
     * Statement lists that run inside the same callable scope.
     *
     * @return list<array<Stmt>>
     */
    private function nestedStatementLists(Stmt $stmt): array
    {
        return array_values(match (true) {
            $stmt instanceof Stmt\If_ => [
                $stmt->stmts,
                ...array_map(static fn(Stmt\ElseIf_ $elseif): array => $elseif->stmts, $stmt->elseifs),
                ...($stmt->else === null ? [] : [$stmt->else->stmts]),
            ],
            $stmt instanceof Stmt\TryCatch => [
                $stmt->stmts,
                ...array_map(static fn(Stmt\Catch_ $catch): array => $catch->stmts, $stmt->catches),
                ...($stmt->finally === null ? [] : [$stmt->finally->stmts]),
            ],
            $stmt instanceof Stmt\Switch_ => array_map(static fn(Stmt\Case_ $case): array => $case->stmts, $stmt->cases),
            $stmt instanceof Stmt\For_, $stmt instanceof Stmt\Foreach_, $stmt instanceof Stmt\While_,
            $stmt instanceof Stmt\Do_, $stmt instanceof Stmt\Block => [$stmt->stmts],
            $stmt instanceof Stmt\Declare_ => [$stmt->stmts ?? []],
            default => [],
        });
    }

    private function isTerminalStatement(Stmt $stmt): bool
    {
        return $stmt instanceof Stmt\Return_
            || $stmt instanceof Stmt\Continue_
            || $stmt instanceof Stmt\Break_
            || $stmt instanceof Stmt\Goto_
            || ($stmt instanceof Stmt\Expression && $this->isTerminalExpression($stmt->expr));
    }

    /**
     * throw and exit/die; `\exit()` / `\die()` parse as function calls since PHP 8.4.
     */
    private function isTerminalExpression(Expr $expr): bool
    {
        if ($expr instanceof Expr\Throw_ || $expr instanceof Expr\Exit_) {
            return true;
        }

        return $expr instanceof Expr\FuncCall
            && $expr->name instanceof Node\Name
            && !$expr->isFirstClassCallable()
            && \in_array($expr->name->toLowerString(), ['exit', 'die'], true);
    }
}
