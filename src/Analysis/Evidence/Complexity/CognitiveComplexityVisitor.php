<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Complexity;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\Case_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Goto_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ResettableVisitorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorCallableScope;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorMethodTrackingTrait;
use Qualimetrix\Core\Path\RelativePath;
use SplObjectStorage;

/**
 * Visitor for calculating Cognitive Complexity.
 *
 * Implements the SonarSource whitepaper (version 1.7, Appendix B):
 * - B1 increments: if, else if, else, ternary, switch, loops, catch, goto and
 *   numbered break/continue, each sequence of like logical operators, and
 *   +1 once for a method that calls itself.
 * - B2 nesting level: raised inside the bodies of if, else if, else, ternary
 *   branches, switch, loops and catch, and inside lambdas. The whitepaper does
 *   not place conditions; here a condition or subject is read as not nested
 *   inside the structure it controls.
 * - B3 nesting increment: if, ternary, switch, loops and catch add the
 *   current nesting level on top of their +1.
 * - `match` is PHP's switch expression and is scored as switch, per the
 *   whitepaper's rule that a language's own spelling of a listed keyword counts.
 * - `??`, `??=` and `?->` add nothing: the whitepaper ignores null-coalescing
 *   operators as shorthand (section "Ignore shorthand", p. 6).
 *
 * Lambdas (closures and arrow functions) get no increment of their own and
 * raise the nesting level of their body by one.
 *
 * Deviations from the whitepaper:
 * - A lambda inside a callable is measured as its own unit, as every other
 *   callable metric measures it, instead of adding its body to the enclosing
 *   callable. The whitepaper's method total is the sum of the callable and
 *   the lambdas it contains.
 * - Recursion is detected only as a direct self-call; a cycle through other
 *   methods is not.
 *
 * @see https://www.sonarsource.com/docs/CognitiveComplexity.pdf
 */
final class CognitiveComplexityVisitor extends NodeVisitorAbstract implements DeclarationIndexAwareInterface, ResettableVisitorInterface
{
    use VisitorMethodTrackingTrait;

    /** @var array<string, int> Method/function FQN => complexity */
    private array $complexities = [];

    /** @var array<string, list<array{type: string, line: int, points: int}>> FQN => increments */
    private array $increments = [];

    /** @var array<string, VisitorCallableScope> */
    private array $scopes = [];

    /** @var array<string, true> Units whose self-call has already been counted */
    private array $recursionCounted = [];

    /**
     * Entered callables, each measured as its own unit.
     *
     * @var list<array{unit: string, nestingLevel: int, nodeStack: list<Node>}>
     */
    private array $callableStack = [];

    /** @var int Current nesting level (0 = top level in method) */
    private int $nestingLevel = 0;

    /** @var list<Node> Stack of ancestor nodes for tree-aware logical operator detection */
    private array $nodeStack = [];

    /** @var SplObjectStorage<Node, int> Body nodes => nesting level they run at */
    private SplObjectStorage $bodyLevels;

    /** @var list<int> Nesting levels saved on entering a body node */
    private array $savedLevels = [];

    public function __construct()
    {
        $this->bodyLevels = new SplObjectStorage();
    }

    public function reset(): void
    {
        $this->complexities = [];
        $this->increments = [];
        $this->scopes = [];
        $this->recursionCounted = [];
        $this->callableStack = [];
        $this->nestingLevel = 0;
        $this->resetVisitorMethodContext();
        $this->nodeStack = [];
        $this->bodyLevels = new SplObjectStorage();
        $this->savedLevels = [];
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
     * Returns tracked complexity increments per method/function.
     *
     * @return array<string, list<array{type: string, line: int, points: int}>>
     */
    public function getIncrements(): array
    {
        /** @var array<string, list<array{type: string, line: int, points: int}>> $projected */
        $projected = $this->projectLogicalMetricMap($this->increments, $this->scopes);

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
            $metrics = (new MetricBag())->with('complexity.cognitive', $this->complexities[$fqn] ?? 0);

            foreach ($this->increments[$fqn] ?? [] as $increment) {
                $metrics = $metrics->withEntry('cognitive-complexity.increments', [
                    'type' => $increment['type'],
                    'line' => $increment['line'],
                    'points' => $increment['points'],
                ]);
            }

            $result[] = $this->createCallableWithMetrics($scope, $file, $metrics);
        }

        return $result;
    }

    public function enterNode(Node $node): ?int
    {
        if ($this->bodyLevels->offsetExists($node)) {
            $this->savedLevels[] = $this->nestingLevel;
            $this->nestingLevel = $this->bodyLevels[$node];
        }

        $scope = $this->enterVisitorMethodContext($node);
        if ($scope !== null) {
            $this->enterCallable($node, $scope);

            return null;
        }

        // Count at the node's own level; only its bodies run one level deeper.
        $this->countComplexity($node);

        // Push AFTER counting so the current node is not in its own ancestor stack.
        $this->nodeStack[] = $node;

        $this->registerBodies($node);

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        $scope = $this->leaveVisitorMethodContext($node);
        if ($scope !== null) {
            $this->leaveCallable();
        } else {
            array_pop($this->nodeStack);
        }

        if ($this->bodyLevels->offsetExists($node)) {
            $this->bodyLevels->offsetUnset($node);
            $this->nestingLevel = array_pop($this->savedLevels) ?? 0;
        }

        return null;
    }

    private function enterCallable(Node $node, VisitorCallableScope $scope): void
    {
        $isNestedLambda = $this->callableStack !== [] && ($node instanceof Closure || $node instanceof ArrowFunction);

        $this->callableStack[] = [
            'unit' => $scope->traversalKey,
            'nestingLevel' => $this->nestingLevel,
            'nodeStack' => $this->nodeStack,
        ];
        $this->complexities[$scope->traversalKey] = 0;
        $this->increments[$scope->traversalKey] = [];
        $this->scopes[$scope->traversalKey] = $scope;
        $this->nodeStack = [];
        $this->nestingLevel = $isNestedLambda ? $this->nestingLevel + 1 : 0;
    }

    private function leaveCallable(): void
    {
        $popped = array_pop($this->callableStack);

        if ($popped !== null) {
            $this->nestingLevel = $popped['nestingLevel'];
            $this->nodeStack = $popped['nodeStack'];
        }
    }

    private function currentUnit(): ?string
    {
        if ($this->callableStack === []) {
            return null;
        }

        return $this->callableStack[array_key_last($this->callableStack)]['unit'];
    }

    /**
     * Marks the bodies a B2 structure nests: statements and branches, never
     * the condition or subject that controls them.
     */
    private function registerBodies(Node $node): void
    {
        $bodies = match (true) {
            $node instanceof If_, $node instanceof ElseIf_, $node instanceof Else_,
            $node instanceof For_, $node instanceof Foreach_, $node instanceof While_,
            $node instanceof Do_, $node instanceof Catch_ => $node->stmts,
            $node instanceof Switch_ => array_merge(...array_map(static fn(Case_ $case): array => $case->stmts, $node->cases)),
            $node instanceof Match_ => array_map(static fn(MatchArm $arm): Node => $arm->body, $node->arms),
            $node instanceof Ternary => array_filter([$node->if, $node->else]),
            default => [],
        };

        foreach ($bodies as $body) {
            $this->bodyLevels[$body] = $this->nestingLevel + 1;
        }
    }

    private function countComplexity(Node $node): void
    {
        $unit = $this->currentUnit();
        if ($unit === null) {
            return;
        }

        $increment = $this->getComplexityIncrement($node, $unit);

        if ($increment > 0) {
            $this->complexities[$unit] += $increment;
            $this->increments[$unit][] = [
                'type' => $this->getNodeTypeLabel($node),
                'line' => $node->getStartLine(),
                'points' => $increment,
            ];
        }
    }

    /**
     * Returns complexity increment for a given node.
     */
    private function getComplexityIncrement(Node $node, string $unit): int
    {
        // B1 + B3: structural increment plus the current nesting level
        if ($node instanceof If_
            || $node instanceof For_
            || $node instanceof Foreach_
            || $node instanceof While_
            || $node instanceof Do_
            || $node instanceof Catch_
            || $node instanceof Switch_
            || $node instanceof Match_
            || $node instanceof Ternary
        ) {
            return 1 + $this->nestingLevel;
        }

        // B1 only: else if, else, goto and numbered jumps take no nesting increment
        if ($node instanceof ElseIf_
            || $node instanceof Else_
            || $node instanceof Goto_
            || ($node instanceof Break_ && $node->num !== null)
            || ($node instanceof Continue_ && $node->num !== null)
        ) {
            return 1;
        }

        if ($this->isLogicalOperator($node)) {
            return $this->getLogicalOperatorIncrement($node);
        }

        if (!isset($this->recursionCounted[$unit]) && $this->isRecursiveCall($node, $unit)) {
            $this->recursionCounted[$unit] = true;

            return 1;
        }

        return 0;
    }

    private function isLogicalOperator(Node $node): bool
    {
        return $node instanceof BooleanAnd
            || $node instanceof LogicalAnd
            || $node instanceof BooleanOr
            || $node instanceof LogicalOr;
    }

    /**
     * Calculates increment for logical operators using tree-aware parent detection.
     *
     * A boolean operator gets +1 if its nearest boolean ancestor in the AST is NOT
     * the same operator type (i.e., it starts a new sequence). If the nearest boolean
     * ancestor IS the same type, it's a continuation of a chain and gets +0.
     *
     * This correctly handles expressions like `$a && $b || $c && $d` where the AST is:
     *   BooleanOr(BooleanAnd($a, $b), BooleanAnd($c, $d))
     * Each BooleanAnd has a BooleanOr parent (different type) -> +1 each.
     * Total logical: +1 (Or) + 1 (left And) + 1 (right And) = +3.
     *
     * For `$a && $b && $c`, the AST is:
     *   BooleanAnd(BooleanAnd($a, $b), $c)
     * Inner BooleanAnd has no boolean ancestor yet -> +1.
     * Outer BooleanAnd has BooleanAnd ancestor (same type) -> +0.
     * Total logical: +1.
     */
    private function getLogicalOperatorIncrement(Node $node): int
    {
        // Walk up the node stack to find the nearest boolean operator ancestor
        for ($i = \count($this->nodeStack) - 1; $i >= 0; $i--) {
            $ancestor = $this->nodeStack[$i];
            if ($this->isLogicalOperator($ancestor)) {
                // Parent is a logical operator - same type means continuation (+0),
                // different type means new sequence (+1)
                return $this->isSameLogicalOperatorType($node, $ancestor) ? 0 : 1;
            }
        }

        // No logical operator ancestor - this is the root of a new boolean expression
        return 1;
    }

    /**
     * Returns a human-readable label for the node type used in breakdown messages.
     */
    private function getNodeTypeLabel(Node $node): string
    {
        return match (true) {
            $node instanceof If_ => 'if',
            $node instanceof ElseIf_ => 'elseif',
            $node instanceof Else_ => 'else',
            $node instanceof For_ => 'for',
            $node instanceof Foreach_ => 'foreach',
            $node instanceof While_ => 'while',
            $node instanceof Do_ => 'do',
            $node instanceof Catch_ => 'catch',
            $node instanceof Switch_ => 'switch',
            $node instanceof Match_ => 'match',
            $node instanceof Ternary => 'ternary',
            $node instanceof Goto_ => 'goto',
            $node instanceof Break_ => 'break',
            $node instanceof Continue_ => 'continue',
            $this->isLogicalOperator($node) => '&&/||',
            $node instanceof FuncCall, $node instanceof MethodCall, $node instanceof StaticCall => 'recursion',
            default => 'other',
        };
    }

    /**
     * Checks if two nodes represent the same logical operator category (and/or).
     */
    private function isSameLogicalOperatorType(Node $a, Node $b): bool
    {
        $isAndA = $a instanceof BooleanAnd || $a instanceof LogicalAnd;
        $isAndB = $b instanceof BooleanAnd || $b instanceof LogicalAnd;

        return $isAndA === $isAndB;
    }

    /**
     * Checks if a call is recursive (calls the unit being measured).
     */
    private function isRecursiveCall(Node $node, string $unit): bool
    {
        $info = $this->scopes[$unit] ?? null;

        if ($info === null) {
            return false;
        }

        $methodName = $info->member;

        // Check for instance method call recursion: only $this->method()
        if ($node instanceof MethodCall) {
            if (!($node->var instanceof Node\Expr\Variable && $node->var->name === 'this')) {
                return false;
            }

            $calledMethod = $node->name instanceof Node\Identifier
                ? $node->name->toString()
                : null;

            return $calledMethod === $methodName;
        }

        // Check for static method call recursion: only self:: and static::
        // parent:: calls the PARENT's method, not the current class — it's not recursion
        if ($node instanceof StaticCall) {
            if (!($node->class instanceof Node\Name)) {
                return false;
            }

            $className = $node->class->toString();
            if ($className !== 'self' && $className !== 'static') {
                return false;
            }

            $calledMethod = $node->name instanceof Node\Identifier
                ? $node->name->toString()
                : null;

            return $calledMethod === $methodName;
        }

        // Check for function call recursion (only inside standalone functions, not class methods).
        // A FuncCall inside a ClassMethod is a call to a global/imported function, NOT recursion.
        // For example, a method named count() calling \count($arr) is not recursive.
        if ($node instanceof FuncCall && $node->name instanceof Node\Name) {
            // Only consider it recursion if we're inside a standalone function (no class context)
            if ($info->class !== null) {
                return false;
            }

            $calledFunction = $node->name->toString();

            // Strip leading backslash (fully-qualified marker) but keep namespace
            if (str_starts_with($calledFunction, '\\')) {
                $calledFunction = substr($calledFunction, 1);
            }

            // If the called function has a namespace (contains \), it cannot match
            // a simple function name — e.g. \Other\Namespace\foo() is not foo()
            if (str_contains($calledFunction, '\\')) {
                return false;
            }

            return $calledFunction === $methodName;
        }

        return false;
    }
}
