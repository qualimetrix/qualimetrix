<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Complexity;

use AiMessDetector\Core\Metric\MethodWithMetrics;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Metrics\ResettableVisitorInterface;
use AiMessDetector\Metrics\VisitorMethodTrackingTrait;
use PhpParser\Node;
use PhpParser\Node\Expr;
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

/**
 * Visitor for calculating NPath Complexity.
 *
 * NPath Complexity counts the number of acyclic execution paths through a method.
 * Unlike Cyclomatic Complexity (additive), NPath is multiplicative and grows exponentially.
 *
 * Algorithm (per Nejmeh, 1988):
 * - Sequence: NPath(S1) × NPath(S2)
 * - if-then: NPath(then) + 1 (1 = skip-path)
 * - if-else: NPath(then) + NPath(else)
 * - while/for/foreach: NPath(cond) + NPath(body) + 1
 * - switch: Σ NPath(case_i)
 * - try-catch: NPath(try) + Σ NPath(catch_i) + 1
 * - ternary: NPath(cond) + NPath(true) + NPath(false)
 * - &&/||: NPath(left) + NPath(right)
 * - ??: NPath(left) + NPath(right)
 * - match: Σ NPath(arm_i)
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

    /** @var array<string, array{namespace: ?string, class: ?string, method: string, line: int}> FQN => method info */
    private array $methodInfos = [];

    /** @var list<array{fqn: string, depth: int}> Stack of nested methods/functions */
    private array $methodStack = [];

    private ?string $currentNamespace = null;
    private ?string $currentClass = null;
    private int $closureCounter = 0;

    /** @var int Depth of anonymous class nesting (methods inside anonymous classes are skipped) */
    private int $anonymousClassDepth = 0;

    public function reset(): void
    {
        $this->npath = [];
        $this->methodInfos = [];
        $this->methodStack = [];
        $this->currentNamespace = null;
        $this->currentClass = null;
        $this->closureCounter = 0;
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
     * Returns structured method metrics for each analyzed method.
     *
     * @return list<MethodWithMetrics>
     */
    public function getMethodsWithMetrics(): array
    {
        $result = [];

        foreach ($this->methodInfos as $fqn => $info) {
            $metrics = (new MetricBag())->with('npath', $this->npath[$fqn] ?? 1);

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
                $npath = $this->calculateSequenceNpath($node->stmts ?? []);
                $this->startMethod($fqn, $node->name->toString(), $node->getStartLine(), $npath);
            }

            return null;
        }

        // Start of a function
        if ($node instanceof Function_) {
            $fqn = $this->buildFunctionFqn($node->name->toString());
            $npath = $this->calculateSequenceNpath($node->stmts ?? []);
            $this->startMethod($fqn, $node->name->toString(), $node->getStartLine(), $npath);

            return null;
        }

        // Start of a closure
        if ($node instanceof Closure) {
            ++$this->closureCounter;
            $fqn = $this->buildClosureFqn();
            $closureName = '{closure#' . $this->closureCounter . '}';
            $npath = $this->calculateSequenceNpath($node->stmts ?? []);
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

        if ($node instanceof Function_ || $node instanceof Closure) {
            $this->endMethod();

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
    private function calculateSequenceNpath(array $stmts): int
    {
        if ($stmts === []) {
            return 1;
        }

        $npath = 1;

        foreach ($stmts as $stmt) {
            $stmtNpath = $this->calculateStmtNpath($stmt);
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
            $stmt instanceof Foreach_ => $this->calculateLoopNpath(null, $stmt->stmts),
            $stmt instanceof Do_ => $this->calculateLoopNpath($stmt->cond, $stmt->stmts),
            $stmt instanceof Switch_ => $this->calculateSwitchNpath($stmt),
            $stmt instanceof TryCatch => $this->calculateTryCatchNpath($stmt),
            $stmt instanceof Stmt\Expression => $this->calculateExprNpath($stmt->expr),
            $stmt instanceof Stmt\Return_ => $stmt->expr !== null
                ? $this->calculateExprNpath($stmt->expr)
                : 1,
            default => 1,
        };
    }

    private function calculateIfNpath(If_ $if): int
    {
        // NPath formula per Nejmeh (1988):
        // - if without else: NPath = NPath(then) + 1 (1 = skip-path)
        // - if with else: NPath = NPath(then) + NPath(else)
        // - if-elseif-...-else: sum of all branches
        $npath = $this->calculateSequenceNpath($if->stmts);

        foreach ($if->elseifs as $elseif) {
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
        $condNpath = $cond !== null ? $this->calculateExprNpath($cond) : 1;
        $npath = $condNpath;
        $npath += $this->calculateSequenceNpath($stmts);
        $npath += 1; // Exit without entering

        return $npath;
    }

    private function calculateForNpath(For_ $for): int
    {
        // For has init, cond, loop expressions
        // NPath = 1 (init) + NPath(cond) + body + exit
        // Multiple conditions in for(;;cond1, cond2) are each evaluated;
        // calculate NPath for each and multiply (each is an execution point).
        $npath = 1; // init
        $condNpath = 1;

        foreach ($for->cond as $condExpr) {
            $condNpath = $this->safeMultiply($condNpath, $this->calculateExprNpath($condExpr));
        }

        $npath += $condNpath;
        $npath += $this->calculateSequenceNpath($for->stmts);
        $npath += 1; // Exit path

        return $npath;
    }

    private function calculateSwitchNpath(Switch_ $switch): int
    {
        // NPath(switch) = 1 (cond) + Σ NPath(case)
        $npath = 1; // switch condition

        foreach ($switch->cases as $case) {
            // Each case adds its body's NPath
            $npath += $this->calculateSequenceNpath($case->stmts);
        }

        return max(1, $npath);
    }

    private function calculateTryCatchNpath(TryCatch $try): int
    {
        // NPath(try) = NPath(try-block) + Σ NPath(catch) + NPath(finally) + 1
        $npath = $this->calculateSequenceNpath($try->stmts);

        foreach ($try->catches as $catch) {
            $npath += $this->calculateSequenceNpath($catch->stmts);
        }

        if ($try->finally !== null) {
            // Finally always executes, multiplicative
            $npath = $this->safeMultiply(
                $npath,
                $this->calculateSequenceNpath($try->finally->stmts),
            );
        }

        return $npath + 1; // Path where no exception occurs
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
            default => 1,
        };
    }

    private function calculateTernaryNpath(Ternary $ternary): int
    {
        // NPath(a ? b : c) = NPath(a) + NPath(b) + NPath(c)
        $npath = $this->calculateExprNpath($ternary->cond);

        if ($ternary->if !== null) {
            $npath += $this->calculateExprNpath($ternary->if);
        } else {
            $npath += 1; // Elvis operator ?: uses cond as result
        }

        $npath += $this->calculateExprNpath($ternary->else);

        return $npath;
    }

    private function calculateBinaryNpath(BinaryOp $binary): int
    {
        // NPath(a && b) = NPath(a) + NPath(b) (short-circuit)
        return $this->calculateExprNpath($binary->left)
            + $this->calculateExprNpath($binary->right);
    }

    private function calculateCoalesceNpath(BinaryOp\Coalesce $coalesce): int
    {
        // NPath(a ?? b) = NPath(a) + NPath(b)
        return $this->calculateExprNpath($coalesce->left)
            + $this->calculateExprNpath($coalesce->right);
    }

    private function calculateMatchNpath(Match_ $match): int
    {
        // NPath(match) = 1 (cond) + Σ NPath(arm)
        $npath = 1; // match condition

        foreach ($match->arms as $arm) {
            // Each arm's body
            $npath += $this->calculateExprNpath($arm->body);
        }

        return max(1, $npath);
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
