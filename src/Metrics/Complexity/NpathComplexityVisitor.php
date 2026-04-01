<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Complexity;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\Ternary;
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
use Qualimetrix\Core\Metric\MethodWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Metrics\ResettableVisitorInterface;
use Qualimetrix\Metrics\VisitorMethodTrackingTrait;

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
 * - match: NPath(cond) + Σ NPath(arm_i)
 *
 * Expression NPath (calculateExprNpath) uses 0-based semantics per Nejmeh:
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

    /**
     * Maximum NPath value to prevent integer overflow (10^9).
     */
    private const MAX_NPATH = 1_000_000_000;

    /** @var array<string, int> Method/function FQN => NPath */
    private array $npath = [];

    /** @var array<string, list<array{type: string, line: int, factor: int}>> FQN => multiplicative factors */
    private array $factors = [];

    /** @var array<string, array{namespace: ?string, class: ?string, method: string, line: int}> FQN => method info */
    private array $methodInfos = [];

    /** @var list<array{fqn: string, depth: int}> Stack of nested methods/functions */
    private array $methodStack = [];

    private ?string $currentNamespace = null;
    private ?string $currentClass = null;
    private int $closureCounter = 0;

    /** @var ?string FQN of the method currently being calculated (for factor tracking) */
    private ?string $calculatingFqn = null;

    /** @var int Depth of anonymous class nesting (methods inside anonymous classes are skipped) */
    private int $anonymousClassDepth = 0;

    public function reset(): void
    {
        $this->npath = [];
        $this->factors = [];
        $this->methodInfos = [];
        $this->methodStack = [];
        $this->currentNamespace = null;
        $this->currentClass = null;
        $this->closureCounter = 0;
        $this->calculatingFqn = null;
        $this->anonymousClassDepth = 0;
    }

    /**
     * @return array<string, int>
     */
    public function getNpath(): array
    {
        return $this->npath;
    }

    /**
     * Returns tracked multiplicative factors per method/function.
     *
     * @return array<string, list<array{type: string, line: int, factor: int}>>
     */
    public function getFactors(): array
    {
        return $this->factors;
    }

    /**
     * Returns structured method metrics for each analyzed method.
     *
     * @return list<MethodWithMetrics>
     */
    public function getMethodsWithMetrics(): array
    {
        $result = [];

        foreach ($this->methodInfos as $fqn => $info) {
            $metrics = (new MetricBag())->with('npath', $this->npath[$fqn] ?? 1);

            foreach ($this->factors[$fqn] ?? [] as $factor) {
                $metrics = $metrics->withEntry('npath-complexity.factors', [
                    'type' => $factor['type'],
                    'line' => $factor['line'],
                    'factor' => $factor['factor'],
                ]);
            }

            $result[] = new MethodWithMetrics(
                namespace: $info['namespace'],
                class: $info['class'],
                method: $info['method'],
                line: $info['line'],
                metrics: $metrics,
            );
        }

        return $result;
    }

    public function enterNode(Node $node): ?int
    {
        // Track namespace
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = $node->name?->toString() ?? '';
        }

        // Track class-like types (skip anonymous classes)
        if ($node instanceof Node\Stmt\Class_) {
            if ($node->name === null) {
                ++$this->anonymousClassDepth;
            } else {
                $this->currentClass = $node->name->toString();
            }
        } elseif ($this->isClassLikeNode($node)) {
            $className = $this->extractClassLikeName($node);
            if ($className !== null) {
                $this->currentClass = $className;
            }
        }

        // Start of a method (skip if inside anonymous class)
        if ($node instanceof ClassMethod) {
            if ($this->anonymousClassDepth === 0) {
                $fqn = $this->buildMethodFqn($node->name->toString());
                $this->calculatingFqn = $fqn;
                $this->factors[$fqn] = [];
                $npath = $this->calculateSequenceNpath($node->stmts ?? [], trackFactors: true);
                $this->calculatingFqn = null;
                $this->startMethod($fqn, $node->name->toString(), $node->getStartLine(), $npath);
            }

            return null;
        }

        // Start of a function
        if ($node instanceof Function_) {
            $fqn = $this->buildFunctionFqn($node->name->toString());
            $this->calculatingFqn = $fqn;
            $this->factors[$fqn] = [];
            $npath = $this->calculateSequenceNpath($node->stmts ?? [], trackFactors: true);
            $this->calculatingFqn = null;
            $this->startMethod($fqn, $node->name->toString(), $node->getStartLine(), $npath);

            return null;
        }

        // Start of a closure (skip if inside anonymous class)
        if ($node instanceof Closure) {
            if ($this->anonymousClassDepth > 0) {
                return null;
            }

            ++$this->closureCounter;
            $fqn = $this->buildClosureFqn();
            $closureName = '{closure#' . $this->closureCounter . '}';
            $this->calculatingFqn = $fqn;
            $this->factors[$fqn] = [];
            $npath = $this->calculateSequenceNpath($node->stmts ?? [], trackFactors: true);
            $this->calculatingFqn = null;
            $this->startMethod($fqn, $closureName, $node->getStartLine(), $npath);

            return null;
        }

        // Start of an arrow function (skip if inside anonymous class)
        if ($node instanceof ArrowFunction) {
            if ($this->anonymousClassDepth > 0) {
                return null;
            }

            ++$this->closureCounter;
            $fqn = $this->buildClosureFqn();
            $closureName = '{closure#' . $this->closureCounter . '}';
            $this->factors[$fqn] = [];
            $npath = max(1, $this->calculateExprNpath($node->expr));
            $this->startMethod($fqn, $closureName, $node->getStartLine(), $npath);

            return null;
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        // End of method (skip if inside anonymous class — we didn't start it)
        if ($node instanceof ClassMethod) {
            if ($this->anonymousClassDepth === 0) {
                $this->endMethod();
            }

            return null;
        }

        if ($node instanceof Function_) {
            $this->endMethod();

            return null;
        }

        if ($node instanceof Closure || $node instanceof ArrowFunction) {
            if ($this->anonymousClassDepth === 0) {
                $this->endMethod();
            }

            return null;
        }

        // Exit class-like scope
        if ($node instanceof Node\Stmt\Class_) {
            if ($node->name === null) {
                --$this->anonymousClassDepth;
            } else {
                $this->currentClass = null;
            }
        } elseif ($this->isClassLikeNode($node)) {
            $this->currentClass = null;
        }

        // Exit namespace scope
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = null;
        }

        return null;
    }

    private function startMethod(string $fqn, string $methodName, int $line, int $npath): void
    {
        $this->methodStack[] = ['fqn' => $fqn, 'depth' => \count($this->methodStack)];
        $this->npath[$fqn] = min($npath, self::MAX_NPATH);
        $this->methodInfos[$fqn] = [
            'namespace' => $this->currentNamespace,
            'class' => $this->currentClass,
            'method' => $methodName,
            'line' => $line,
        ];
    }

    private function endMethod(): void
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
                    'factor' => min($stmtNpath, self::MAX_NPATH),
                ];
            }

            $npath = $this->safeMultiply($npath, $stmtNpath);

            if ($npath >= self::MAX_NPATH) {
                return self::MAX_NPATH;
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
            $stmt instanceof Foreach_ => $this->calculateSequenceNpath($stmt->stmts) + 1,
            $stmt instanceof Do_ => $this->calculateLoopNpath($stmt->cond, $stmt->stmts),
            $stmt instanceof Switch_ => $this->calculateSwitchNpath($stmt),
            $stmt instanceof TryCatch => $this->calculateTryCatchNpath($stmt),
            $stmt instanceof Stmt\Expression => max(1, $this->calculateExprNpath($stmt->expr)),
            $stmt instanceof Stmt\Return_ => $stmt->expr !== null
                ? max(1, $this->calculateExprNpath($stmt->expr))
                : 1,
            default => 1,
        };
    }

    private function calculateIfNpath(If_ $if): int
    {
        // NPath formula per Nejmeh (1988):
        // NPath(if) = NPath(cond) + NPath(then) + NPath(else)
        // - if without else: NPath = NPath(cond) + NPath(then) + 1 (1 = skip-path)
        // - if with else: NPath = NPath(cond) + NPath(then) + NPath(else)
        // - if-elseif-...-else: NPath(cond) + sum of all branches
        $npath = $this->calculateExprNpath($if->cond);
        $npath += $this->calculateSequenceNpath($if->stmts);

        foreach ($if->elseifs as $elseif) {
            $npath += $this->calculateExprNpath($elseif->cond);
            $npath += $this->calculateSequenceNpath($elseif->stmts);
        }

        if ($if->else !== null) {
            $npath += $this->calculateSequenceNpath($if->else->stmts);
        } else {
            // Implicit else path (skip-path)
            $npath += 1;
        }

        return $npath;
    }

    /**
     * @param array<Stmt> $stmts
     */
    private function calculateLoopNpath(?Expr $cond, array $stmts): int
    {
        // NPath(loop) = NPath(cond) + NPath(body) + 1 (exit path)
        $condNpath = $cond !== null ? $this->calculateExprNpath($cond) : 0;
        $npath = $condNpath;
        $npath += $this->calculateSequenceNpath($stmts);
        $npath += 1; // Exit without entering

        return $npath;
    }

    private function calculateForNpath(For_ $for): int
    {
        // Nejmeh 1988: NPath(for) = NPath(cond) + NPath(body) + 1
        // Same as while: condition paths + body paths + exit path
        $condNpath = 0;

        foreach ($for->cond as $condExpr) {
            $condNpath += $this->calculateExprNpath($condExpr);
        }

        return $condNpath + $this->calculateSequenceNpath($for->stmts) + 1;
    }

    private function calculateSwitchNpath(Switch_ $switch): int
    {
        // NPath(switch) = NPath(cond) + Σ NPath(case)
        $npath = $this->calculateExprNpath($switch->cond);

        foreach ($switch->cases as $case) {
            // Each case adds its body's NPath
            $npath += $this->calculateSequenceNpath($case->stmts);
        }

        return max(1, $npath);
    }

    private function calculateTryCatchNpath(TryCatch $try): int
    {
        // PMD/Checkstyle formula: (NPath(try) + Σ NPath(catch) + 1) * NPath(finally)
        // The +1 accounts for the path where no exception is thrown.
        // Note: this follows PMD convention (not original Nejmeh 1988, which predates exceptions).
        // Reviewed and confirmed as intentional — matches industry-standard tools.
        $npath = $this->calculateSequenceNpath($try->stmts);

        foreach ($try->catches as $catch) {
            $npath += $this->calculateSequenceNpath($catch->stmts);
        }

        $npath += 1; // Path where no exception occurs

        if ($try->finally !== null) {
            // Finally always executes, multiplicative with all paths
            $npath = $this->safeMultiply(
                $npath,
                $this->calculateSequenceNpath($try->finally->stmts),
            );
        }

        return $npath;
    }

    private function calculateExprNpath(Expr $expr): int
    {
        return match (true) {
            $expr instanceof Ternary => $this->calculateTernaryNpath($expr),
            $expr instanceof BinaryOp\BooleanAnd,
            $expr instanceof BinaryOp\LogicalAnd => $this->calculateBinaryNpath($expr),
            $expr instanceof BinaryOp\BooleanOr,
            $expr instanceof BinaryOp\LogicalOr => $this->calculateBinaryNpath($expr),
            $expr instanceof BinaryOp\Coalesce => $this->calculateCoalesceNpath($expr),
            $expr instanceof Match_ => $this->calculateMatchNpath($expr),
            $expr instanceof Expr\Assign => $this->calculateExprNpath($expr->expr),
            $expr instanceof Expr\AssignOp => $this->calculateExprNpath($expr->expr),
            $expr instanceof Expr\BooleanNot => $this->calculateExprNpath($expr->expr),
            default => 0,
        };
    }

    private function calculateTernaryNpath(Ternary $ternary): int
    {
        // Nejmeh 1988: NPath(a ? b : c) = NPath(a) + NPath(b) + NPath(c) + 2
        // The +2 represents the two base branch paths (true-branch and false-branch).
        $npath = $this->calculateExprNpath($ternary->cond);

        if ($ternary->if !== null) {
            $npath += $this->calculateExprNpath($ternary->if);
        } else {
            // Elvis operator (?:): true-branch reuses cond, no additional expr paths
            $npath += 0;
        }

        $npath += $this->calculateExprNpath($ternary->else);

        // +2 for the two base branch paths
        $npath += 2;

        return $npath;
    }

    private function calculateBinaryNpath(BinaryOp $binary): int
    {
        // Nejmeh 1988: each &&/|| operator adds one additional short-circuit path.
        // NPath(a && b) = NPath(a) + NPath(b) + 1
        return $this->calculateExprNpath($binary->left)
            + $this->calculateExprNpath($binary->right)
            + 1;
    }

    private function calculateCoalesceNpath(BinaryOp\Coalesce $coalesce): int
    {
        // Null-coalesce acts like a boolean short-circuit: +1 for the null-check path
        // NPath(a ?? b) = NPath(a) + NPath(b) + 1
        return $this->calculateExprNpath($coalesce->left)
            + $this->calculateExprNpath($coalesce->right)
            + 1;
    }

    private function calculateMatchNpath(Match_ $match): int
    {
        // NPath(match) = NPath(cond) + Σ NPath(arm)
        $npath = $this->calculateExprNpath($match->cond);

        foreach ($match->arms as $arm) {
            // Each arm's body
            $npath += $this->calculateExprNpath($arm->body);
        }

        return max(1, $npath);
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
            $expr instanceof Match_ => 'match',
            $expr instanceof Expr\Assign, $expr instanceof Expr\AssignOp => $this->getExprTypeLabel($expr->expr),
            default => 'expr',
        };
    }

    private function safeMultiply(int $a, int $b): int
    {
        // Prevent overflow
        if ($a >= self::MAX_NPATH || $b >= self::MAX_NPATH) {
            return self::MAX_NPATH;
        }

        $result = $a * $b;

        return min($result, self::MAX_NPATH);
    }

}
