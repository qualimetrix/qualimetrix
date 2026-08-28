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
 * Scans the top-level statement list of methods and functions.
 * After a terminal statement (return, throw, exit/die, continue, break),
 * any subsequent statements in the SAME list are unreachable.
 *
 * Does NOT recursively check inside if/else/try blocks.
 * Closures are intentionally skipped.
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
     * @return array{int, ?int}
     */
    private function analyzeStatementList(array $stmts): array
    {
        $foundTerminal = false;
        $unreachableCount = 0;
        $firstLine = null;

        foreach ($stmts as $stmt) {
            if ($stmt instanceof \PhpParser\Node\Stmt\Nop) {
                continue;
            }

            if ($foundTerminal) {
                // A goto label is a valid jump target — it resets reachability
                if ($stmt instanceof Stmt\Label) {
                    $foundTerminal = false;

                    continue;
                }

                $unreachableCount++;
                $firstLine ??= $stmt->getStartLine();

                continue;
            }

            if ($this->isTerminalStatement($stmt)) {
                $foundTerminal = true;
            }
        }

        return [$unreachableCount, $firstLine];
    }

    private function isTerminalStatement(Stmt $stmt): bool
    {
        // return
        if ($stmt instanceof Stmt\Return_) {
            return true;
        }

        // continue
        if ($stmt instanceof Stmt\Continue_) {
            return true;
        }

        // break
        if ($stmt instanceof Stmt\Break_) {
            return true;
        }

        // goto
        if ($stmt instanceof Stmt\Goto_) {
            return true;
        }

        // throw (Stmt\Expression wrapping Expr\Throw_)
        // exit/die (Stmt\Expression wrapping Expr\Exit_)
        if ($stmt instanceof Stmt\Expression) {
            return $stmt->expr instanceof Expr\Throw_
                || $stmt->expr instanceof Expr\Exit_;
        }

        return false;
    }
}
