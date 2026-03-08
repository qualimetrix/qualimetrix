<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Complexity;

use AiMessDetector\Core\Metric\MethodWithMetrics;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Metrics\ResettableVisitorInterface;
use AiMessDetector\Metrics\VisitorMethodTrackingTrait;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Stmt\Case_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeVisitorAbstract;

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
 * - ?: (ternary): +1
 * - ?? (null coalescing): +1
 * - ?-> (nullsafe): +1
 */
final class CyclomaticComplexityVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    use VisitorMethodTrackingTrait;

    /** @var array<string, int> Method/function FQN => complexity */
    private array $complexities = [];

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
        $this->complexities = [];
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
    public function getComplexities(): array
    {
        return $this->complexities;
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
            $metrics = (new MetricBag())->with('ccn', $this->complexities[$fqn] ?? 1);

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
                $this->startMethod($fqn, $node->name->toString(), $node->getStartLine());
            }

            return null;
        }

        // Start of a function
        if ($node instanceof Function_) {
            $fqn = $this->buildFunctionFqn($node->name->toString());
            $this->startMethod($fqn, $node->name->toString(), $node->getStartLine());

            return null;
        }

        // Start of a closure or arrow function
        if ($node instanceof Closure || $node instanceof ArrowFunction) {
            ++$this->closureCounter;
            $fqn = $this->buildClosureFqn();
            $closureName = '{closure#' . $this->closureCounter . '}';
            $this->startMethod($fqn, $closureName, $node->getStartLine());

            return null;
        }

        // Count decision points
        $this->countDecisionPoint($node);

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

        if ($node instanceof Function_ || $node instanceof Closure || $node instanceof ArrowFunction) {
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

    private function startMethod(string $fqn, string $methodName, int $line): void
    {
        $this->methodStack[] = ['fqn' => $fqn, 'depth' => \count($this->methodStack)];
        // Initialize with base complexity of 1
        $this->complexities[$fqn] = 1;
        // Store method info for later retrieval
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

    private function countDecisionPoint(Node $node): void
    {
        if ($this->methodStack === []) {
            return;
        }

        // Don't count decision points inside anonymous classes
        if ($this->anonymousClassDepth > 0) {
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

        // Match arm: +1 for each non-default arm
        if ($node instanceof MatchArm && $node->conds !== null && $node->conds !== []) {
            return 1;
        }

        return 0;
    }

}
