<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Complexity;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\BinaryOp\LogicalXor;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\MatchArm;
use PhpParser\Node\PropertyHook;
use PhpParser\Node\Stmt\Case_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Core\Metric\CallableWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Metrics\ResettableVisitorInterface;
use Qualimetrix\Metrics\VisitorMethodTrackingTrait;

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
final class CyclomaticComplexityVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    use VisitorMethodTrackingTrait;

    /** @var array<string, int> Method/function FQN => complexity */
    private array $complexities = [];

    /** @var array<string, array{logicalFqn: string, namespace: ?string, class: ?string, method: string, startFilePos: int, sourceLine: int, kind: CallableKind, anonymousSyntax: ?string, classStartFilePos: ?int}> traversal key => callable info */
    private array $methodInfos = [];

    /** @var list<array{fqn: string, depth: int}> Stack of nested methods/functions */
    private array $methodStack = [];

    private ?string $currentNamespace = null;
    private ?string $currentClass = null;
    private ?int $currentClassStartFilePos = null;
    private int $closureCounter = 0;
    private ?string $currentProperty = null;
    /** @var list<array{?string, ?int}> */
    private array $classContextStack = [];

    public function reset(): void
    {
        $this->complexities = [];
        $this->methodInfos = [];
        $this->methodStack = [];
        $this->currentNamespace = null;
        $this->currentClass = null;
        $this->currentClassStartFilePos = null;
        $this->closureCounter = 0;
        $this->currentProperty = null;
        $this->classContextStack = [];
        $this->resetCallableTraversalKeys();
    }

    /**
     * @return array<string, int>
     */
    public function getComplexities(): array
    {
        /** @var array<string, int> $projected */
        $projected = $this->projectLogicalMetricMap($this->complexities, $this->methodInfos);

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

        $ordinals = $this->callableCollisionOrdinals($this->methodInfos);
        foreach ($this->methodInfos as $fqn => $info) {
            $metrics = (new MetricBag())->with('ccn', $this->complexities[$fqn] ?? 1);

            $result[] = $this->createCallableWithMetrics($info, $file, $metrics, $ordinals[$fqn]);
        }

        return $result;
    }

    public function enterNode(Node $node): ?int
    {
        // Track namespace
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = $node->name?->toString() ?? '';
        }

        if ($this->isClassLikeNode($node)) {
            $this->classContextStack[] = [$this->currentClass, $this->currentClassStartFilePos];
        }

        // Track class-like types (skip anonymous classes)
        if ($node instanceof Node\Stmt\Class_) {
            if ($node->name === null) {
                $this->currentClass = $this->buildAnonymousClassName($node->getStartFilePos());
                $this->currentClassStartFilePos = $node->getStartFilePos();
            } else {
                $this->currentClass = $node->name->toString();
                $this->currentClassStartFilePos = $node->getStartFilePos();
            }
        } elseif ($this->isClassLikeNode($node)) {
            $className = $this->extractClassLikeName($node);
            if ($className !== null) {
                $this->currentClass = $className;
                $this->currentClassStartFilePos = $node->getStartFilePos();
            }
        }

        if ($node instanceof ClassMethod) {
            $fqn = $this->buildMethodFqn($node->name->toString());
            $this->startMethod($fqn, $node->name->toString(), $node->getStartFilePos(), $node->getStartLine(), CallableKind::Method, null);

            return null;
        }

        if ($node instanceof Property && $this->currentClass !== null && \count($node->props) === 1) {
            $this->currentProperty = $node->props[0]->name->toString();
        }

        if ($node instanceof PropertyHook && $this->currentClass !== null && $this->currentProperty !== null) {
            $name = $this->currentProperty . '::' . $node->name->toString();
            $this->startMethod(
                $this->buildMethodFqn($name),
                $name,
                $node->getStartFilePos(),
                $node->getStartLine(),
                CallableKind::PropertyHook,
                null,
            );

            return null;
        }

        // Start of a function
        if ($node instanceof Function_) {
            $fqn = $this->buildFunctionFqn($node->name->toString());
            $this->startMethod($fqn, $node->name->toString(), $node->getStartFilePos(), $node->getStartLine(), CallableKind::Function, null);

            return null;
        }

        if ($node instanceof Closure || $node instanceof ArrowFunction) {
            ++$this->closureCounter;
            $fqn = $this->buildClosureFqn();
            $closureName = '{closure#' . $this->closureCounter . '}';
            $this->startMethod(
                $fqn,
                $closureName,
                $node->getStartFilePos(),
                $node->getStartLine(),
                CallableKind::AnonymousCallable,
                $node instanceof Closure ? 'closure' : 'arrow',
            );

            return null;
        }

        // Count decision points
        $this->countDecisionPoint($node);

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof ClassMethod) {
            $this->endMethod();

            return null;
        }

        if ($node instanceof PropertyHook) {
            if ($this->currentClass !== null && $this->currentProperty !== null) {
                $this->endMethod();
            }

            return null;
        }

        if ($node instanceof Property) {
            $this->currentProperty = null;
        }

        if ($node instanceof Function_) {
            $this->endMethod();

            return null;
        }

        if ($node instanceof Closure || $node instanceof ArrowFunction) {
            $this->endMethod();

            return null;
        }

        // Exit class-like scope
        if ($node instanceof Node\Stmt\Class_) {
            [$this->currentClass, $this->currentClassStartFilePos] = array_pop($this->classContextStack) ?? [null, null];
        } elseif ($this->isClassLikeNode($node)) {
            [$this->currentClass, $this->currentClassStartFilePos] = array_pop($this->classContextStack) ?? [null, null];
        }

        // Exit namespace scope
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = null;
        }

        return null;
    }

    private function startMethod(
        string $fqn,
        string $methodName,
        int $startFilePos,
        int $sourceLine,
        CallableKind $kind,
        ?string $anonymousSyntax,
    ): void {
        $logicalFqn = $fqn;
        $fqn = $this->createCallableTraversalKey($logicalFqn, $startFilePos);
        $this->methodStack[] = ['fqn' => $fqn, 'depth' => \count($this->methodStack)];
        // Initialize with base complexity of 1
        $this->complexities[$fqn] = 1;
        // Store method info for later retrieval
        $this->methodInfos[$fqn] = [
            'namespace' => $this->currentNamespace,
            'logicalFqn' => $logicalFqn,
            'class' => $this->currentClass,
            'method' => $methodName,
            'startFilePos' => $startFilePos,
            'sourceLine' => $sourceLine,
            'kind' => $kind,
            'anonymousSyntax' => $anonymousSyntax,
            'classStartFilePos' => $this->currentClassStartFilePos,
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
