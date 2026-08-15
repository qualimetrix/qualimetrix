<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Complexity;

use LogicException;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\PropertyHook;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ResettableVisitorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorCallableScope;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorMethodTrackingTrait;
use Qualimetrix\Core\Path\RelativePath;

/**
 * Visitor for calculating NPath Complexity.
 *
 * NPath Complexity counts the number of acyclic execution paths through a method.
 * Unlike Cyclomatic Complexity (additive), NPath is multiplicative and grows exponentially.
 *
 * Algorithm (per Nejmeh, 1988):
 * - Sequence: NPath(S1) × NPath(S2)
 * - if-then: NPath(cond) + NPath(then) + 1 (1 = skip-path)
 * - if-else: NPath(cond) + NPath(then) + NPath(else)
 * - while/for/foreach: NPath(cond) + NPath(body) + 1
 * - switch: NPath(cond) + Σ NPath(case_i)
 * - try-catch: NPath(try) + Σ NPath(catch_i) + 1
 * - ternary: NPath(cond) + NPath(true) + NPath(false) + 2
 * - &&/||: NPath(left) + NPath(right) + 1
 * - ??: NPath(left) + NPath(right) + 1
 * - match: NPath(subject) + Σ(NPath(arm conditions) + max(1, NPath(arm body)))
 *
 * Expression NPath uses 0-based semantics per Nejmeh:
 * - Leaf expression: 0 (no additional paths from boolean short-circuit)
 * - Each &&/||/?? operator: +1 (one additional short-circuit path)
 * - Ternary: +2 (two base branch paths)
 *
 * Statement NPath uses max(1, exprNpath) to ensure simple statements
 * contribute at least 1 path in multiplicative sequences.
 */
final class NpathComplexityVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    use VisitorMethodTrackingTrait;

    /** @var array<string, int> Method/function FQN => NPath */
    private array $npath = [];

    /** @var array<string, list<array{type: string, line: int, factor: int}>> FQN => multiplicative factors */
    private array $factors = [];

    /** @var array<string, VisitorCallableScope> */
    private array $scopes = [];

    /** @var list<array{fqn: string, depth: int}> Stack of nested methods/functions */
    private array $methodStack = [];

    /** @var ?string FQN of the method currently being calculated (for factor tracking) */
    private ?string $calculatingFqn = null;

    private readonly NpathExpressionCalculator $expressionCalculator;

    public function __construct()
    {
        $this->expressionCalculator = new NpathExpressionCalculator();
    }

    public function reset(): void
    {
        $this->npath = [];
        $this->factors = [];
        $this->scopes = [];
        $this->methodStack = [];
        $this->resetVisitorMethodContext();
        $this->calculatingFqn = null;
    }

    /**
     * @return array<string, int>
     */
    public function getNpath(): array
    {
        /** @var array<string, int> $projected */
        $projected = $this->projectLogicalMetricMap($this->npath, $this->scopes);

        return $projected;
    }

    /**
     * Returns tracked multiplicative factors per method/function.
     *
     * @return array<string, list<array{type: string, line: int, factor: int}>>
     */
    public function getFactors(): array
    {
        /** @var array<string, list<array{type: string, line: int, factor: int}>> $projected */
        $projected = $this->projectLogicalMetricMap($this->factors, $this->scopes);

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

        $ordinals = $this->callableCollisionOrdinals($this->scopes);
        foreach ($this->scopes as $fqn => $scope) {
            $metrics = (new MetricBag())->with('npath', $this->npath[$fqn] ?? 1);

            foreach ($this->factors[$fqn] ?? [] as $factor) {
                $metrics = $metrics->withEntry('npath-complexity.factors', [
                    'type' => $factor['type'],
                    'line' => $factor['line'],
                    'factor' => $factor['factor'],
                ]);
            }

            $result[] = $this->createCallableWithMetrics($scope, $file, $metrics, $ordinals[$fqn]);
        }

        return $result;
    }

    /**
     */
    public function enterNode(Node $node): ?int
    {
        $scope = $this->enterVisitorMethodContext($node);
        if ($scope === null) {
            return null;
        }
        $this->calculatingFqn = $scope->traversalKey;
        $this->factors[$scope->traversalKey] = [];
        $npath = match (true) {
            $node instanceof ClassMethod, $node instanceof Function_, $node instanceof Closure => $this->calculateSequenceNpath($node->stmts ?? [], trackFactors: true),
            $node instanceof PropertyHook => $node->body instanceof Expr
                ? $this->calculateCallableExpressionNpath($node->body)
                : $this->calculateSequenceNpath($node->body ?? [], trackFactors: true),
            $node instanceof ArrowFunction => $this->calculateCallableExpressionNpath($node->expr),
            default => throw new LogicException('Callable scope requires a callable AST node'),
        };
        $this->calculatingFqn = null;
        $this->startMethod($scope, $npath);

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

    private function startMethod(VisitorCallableScope $scope, int $npath): void
    {
        $fqn = $scope->traversalKey;
        $this->methodStack[] = ['fqn' => $fqn, 'depth' => \count($this->methodStack)];
        $this->npath[$fqn] = min($npath, NpathExpressionCalculator::MAX_NPATH);
        $this->scopes[$fqn] = $scope;
    }

    private function endMethod(VisitorCallableScope $scope): void
    {
        array_pop($this->methodStack);
    }

    /**
     * NPath for a sequence of statements (multiplicative).
     *
     * @param array<Stmt> $stmts
     */
    private function calculateSequenceNpath(array $stmts, bool $trackFactors = false): int
    {
        if ($stmts === []) {
            return 1;
        }

        $npath = 1;

        foreach ($stmts as $stmt) {
            $stmtNpath = $this->calculateStmtNpath($stmt);

            if ($trackFactors && $stmtNpath > 1 && $this->calculatingFqn !== null) {
                $this->factors[$this->calculatingFqn][] = [
                    'type' => $this->getStmtTypeLabel($stmt),
                    'line' => $stmt->getStartLine(),
                    'factor' => min($stmtNpath, NpathExpressionCalculator::MAX_NPATH),
                ];
            }

            $npath = $this->expressionCalculator->saturatingMultiply($npath, $stmtNpath);

            if ($npath >= NpathExpressionCalculator::MAX_NPATH) {
                return NpathExpressionCalculator::MAX_NPATH;
            }
        }

        return $npath;
    }

    private function calculateStmtNpath(Stmt $stmt): int
    {
        return match (true) {
            $stmt instanceof If_ => $this->calculateIfNpath($stmt),
            $stmt instanceof While_ => $this->calculateLoopNpath($stmt->cond, $stmt->stmts),
            $stmt instanceof For_ => $this->calculateForNpath($stmt),
            $stmt instanceof Foreach_ => $this->calculateForeachNpath($stmt),
            $stmt instanceof Do_ => $this->calculateLoopNpath($stmt->cond, $stmt->stmts),
            $stmt instanceof Switch_ => $this->calculateSwitchNpath($stmt),
            $stmt instanceof TryCatch => $this->calculateTryCatchNpath($stmt),
            $stmt instanceof Stmt\Expression => $this->calculateCallableExpressionNpath($stmt->expr),
            $stmt instanceof Stmt\Return_ => $stmt->expr !== null
                ? $this->calculateCallableExpressionNpath($stmt->expr)
                : 1,
            $stmt instanceof Stmt\Echo_ => $this->calculateCallableExpressionsNpath($stmt->exprs),
            default => 1,
        };
    }

    /**
     * Nullsafe accesses have zero-based expression contributions. Their
     * enclosing callable statement supplies the missing base path exactly
     * once, so one access has NPath 2 and a two-access chain has NPath 3.
     */
    private function calculateCallableExpressionNpath(Expr $expression): int
    {
        $contributions = $this->expressionCalculator->calculateContributions($expression);

        return $this->expressionCalculator->saturatingAdd(
            max(1, $contributions['ordinary']),
            $contributions['nullsafe'],
        );
    }

    /** @param array<Expr> $expressions */
    private function calculateCallableExpressionsNpath(array $expressions): int
    {
        $ordinary = 0;
        $nullsafe = 0;

        foreach ($expressions as $expression) {
            $contributions = $this->expressionCalculator->calculateContributions($expression);
            $ordinary = $this->expressionCalculator->saturatingAdd($ordinary, $contributions['ordinary']);
            $nullsafe = $this->expressionCalculator->saturatingAdd($nullsafe, $contributions['nullsafe']);
        }

        return $this->expressionCalculator->saturatingAdd(max(1, $ordinary), $nullsafe);
    }

    private function calculateIfNpath(If_ $if): int
    {
        // NPath formula per Nejmeh (1988):
        // NPath(if) = NPath(cond) + NPath(then) + NPath(else)
        // - if without else: NPath = NPath(cond) + NPath(then) + 1 (1 = skip-path)
        // - if with else: NPath = NPath(cond) + NPath(then) + NPath(else)
        // - if-elseif-...-else: NPath(cond) + sum of all branches
        $npath = $this->expressionCalculator->calculate($if->cond);
        $npath = $this->expressionCalculator->saturatingAdd($npath, $this->calculateSequenceNpath($if->stmts));

        foreach ($if->elseifs as $elseif) {
            $npath = $this->expressionCalculator->saturatingAdd(
                $npath,
                $this->expressionCalculator->calculate($elseif->cond),
                $this->calculateSequenceNpath($elseif->stmts),
            );
        }

        if ($if->else !== null) {
            $npath = $this->expressionCalculator->saturatingAdd($npath, $this->calculateSequenceNpath($if->else->stmts));
        } else {
            // Implicit else path (skip-path)
            $npath = $this->expressionCalculator->saturatingAdd($npath, 1);
        }

        return $npath;
    }

    /**
     * @param array<Stmt> $stmts
     */
    private function calculateLoopNpath(?Expr $cond, array $stmts): int
    {
        // NPath(loop) = NPath(cond) + NPath(body) + 1 (exit path)
        return $this->expressionCalculator->saturatingAdd(
            $cond !== null ? $this->expressionCalculator->calculate($cond) : 0,
            $this->calculateSequenceNpath($stmts),
            1,
        );
    }

    private function calculateForNpath(For_ $for): int
    {
        // Nejmeh 1988: NPath(for) = NPath(cond) + NPath(body) + 1
        // Same as while: condition paths + body paths + exit path
        return $this->expressionCalculator->saturatingAdd(
            $this->calculateExpressions($for->init),
            $this->calculateExpressions($for->cond),
            $this->calculateExpressions($for->loop),
            $this->calculateSequenceNpath($for->stmts),
            1,
        );
    }

    private function calculateForeachNpath(Foreach_ $foreach): int
    {
        return $this->expressionCalculator->saturatingAdd(
            $this->expressionCalculator->calculate($foreach->expr),
            $foreach->keyVar instanceof Expr ? $this->expressionCalculator->calculate($foreach->keyVar) : 0,
            $this->expressionCalculator->calculate($foreach->valueVar),
            $this->calculateSequenceNpath($foreach->stmts),
            1,
        );
    }

    private function calculateSwitchNpath(Switch_ $switch): int
    {
        // NPath(switch) = NPath(cond) + Σ NPath(case)
        $npath = $this->expressionCalculator->calculate($switch->cond);

        foreach ($switch->cases as $case) {
            $npath = $this->expressionCalculator->saturatingAdd(
                $npath,
                $case->cond !== null ? $this->expressionCalculator->calculate($case->cond) : 0,
                max(1, $this->calculateSequenceNpath($case->stmts)),
            );
        }

        return max(1, $npath);
    }

    private function calculateTryCatchNpath(TryCatch $try): int
    {
        // (NPath(try) + Σ NPath(catch) + 1) * NPath(finally). The +1 accounts
        // for the no-exception path — an additive PMD-style term that gives
        // qmx NPath=3 for a bare try/catch where the original Nejmeh 1988
        // scheme (which predates exceptions) would imply 2. This is a
        // deliberate qmx choice, not an attempt at exact PMD parity.
        $npath = $this->calculateSequenceNpath($try->stmts);

        foreach ($try->catches as $catch) {
            $npath = $this->expressionCalculator->saturatingAdd($npath, $this->calculateSequenceNpath($catch->stmts));
        }

        $npath = $this->expressionCalculator->saturatingAdd($npath, 1);

        if ($try->finally !== null) {
            // Finally always executes, multiplicative with all paths
            $npath = $this->expressionCalculator->saturatingMultiply(
                $npath,
                $this->calculateSequenceNpath($try->finally->stmts),
            );
        }

        return $npath;
    }

    /** @param array<Expr> $expressions */
    private function calculateExpressions(array $expressions): int
    {
        $npath = 0;

        foreach ($expressions as $expression) {
            $npath = $this->expressionCalculator->saturatingAdd(
                $npath,
                $this->expressionCalculator->calculate($expression),
            );
        }

        return $npath;
    }

    /**
     * Returns a human-readable label for a statement type used in breakdown messages.
     */
    private function getStmtTypeLabel(Stmt $stmt): string
    {
        return match (true) {
            $stmt instanceof If_ => $stmt->else !== null || $stmt->elseifs !== [] ? 'if/else' : 'if',
            $stmt instanceof While_ => 'while',
            $stmt instanceof For_ => 'for',
            $stmt instanceof Foreach_ => 'foreach',
            $stmt instanceof Do_ => 'do',
            $stmt instanceof Switch_ => 'switch',
            $stmt instanceof TryCatch => 'try/catch',
            $stmt instanceof Stmt\Expression => $this->getExprTypeLabel($stmt->expr),
            $stmt instanceof Stmt\Return_ => $stmt->expr !== null ? $this->getExprTypeLabel($stmt->expr) : 'return',
            default => 'stmt',
        };
    }

    /**
     * Returns a human-readable label for an expression type.
     */
    private function getExprTypeLabel(Expr $expr): string
    {
        return match (true) {
            $expr instanceof Ternary => 'ternary',
            $expr instanceof BinaryOp\BooleanAnd, $expr instanceof BinaryOp\LogicalAnd => '&&/||',
            $expr instanceof BinaryOp\BooleanOr, $expr instanceof BinaryOp\LogicalOr => '&&/||',
            $expr instanceof BinaryOp\Coalesce => '??',
            $expr instanceof Expr\AssignOp\Coalesce => '??=',
            $expr instanceof Expr\Match_ => 'match',
            $expr instanceof Expr\Assign, $expr instanceof Expr\AssignOp => $this->getExprTypeLabel($expr->expr),
            default => 'expr',
        };
    }

}
