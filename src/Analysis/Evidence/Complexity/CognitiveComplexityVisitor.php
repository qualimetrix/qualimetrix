<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Complexity;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\PropertyHook;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Goto_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ResettableVisitorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorCallableScope;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorMethodTrackingTrait;
use Qualimetrix\Core\Path\RelativePath;

/**
 * Visitor for calculating Cognitive Complexity.
 *
 * Cognitive Complexity measures code understandability, not just execution paths.
 *
 * Key differences from Cyclomatic Complexity:
 * - Nesting increments complexity: deeper = harder to understand
 * - Logical operator chains: "a && b && c" = +1 (one chain), not +3
 * - Switch is +1 regardless of case count
 * - Recursion adds +1
 *
 * Rules:
 * - Control structures (if, for, while, etc.): +1 + nesting level
 * - Logical operators: +1 for each sequence of same operator
 * - Switch/Match: +1 + nesting level
 * - Catch blocks: +1 + nesting level
 * - Goto, labeled break/continue: +1 (no nesting bonus, per SonarSource B1)
 * - Ternary, null coalescing: +1 (no nesting bonus)
 * - Recursion: +1
 *
 * @see https://www.sonarsource.com/docs/CognitiveComplexity.pdf
 */
final class CognitiveComplexityVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    use VisitorMethodTrackingTrait;

    /** @var array<string, int> Method/function FQN => complexity */
    private array $complexities = [];

    /** @var array<string, list<array{type: string, line: int, points: int}>> FQN => increments */
    private array $increments = [];

    /** @var array<string, VisitorCallableScope> */
    private array $scopes = [];

    /** @var list<array{fqn: string, depth: int, nestingLevel: int, nodeStack: list<Node>}> Stack of nested methods/functions */
    private array $methodStack = [];

    /** @var int Current nesting level (0 = top level in method) */
    private int $nestingLevel = 0;

    /** @var list<Node> Stack of ancestor nodes for tree-aware logical operator detection */
    private array $nodeStack = [];

    public function reset(): void
    {
        $this->complexities = [];
        $this->increments = [];
        $this->scopes = [];
        $this->methodStack = [];
        $this->nestingLevel = 0;
        $this->resetVisitorMethodContext();
        $this->nodeStack = [];
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

        $ordinals = $this->callableCollisionOrdinals($this->scopes);
        foreach ($this->scopes as $fqn => $scope) {
            $metrics = (new MetricBag())->with('cognitive', $this->complexities[$fqn] ?? 0);

            foreach ($this->increments[$fqn] ?? [] as $increment) {
                $metrics = $metrics->withEntry('cognitive-complexity.increments', [
                    'type' => $increment['type'],
                    'line' => $increment['line'],
                    'points' => $increment['points'],
                ]);
            }

            $result[] = $this->createCallableWithMetrics($scope, $file, $metrics, $ordinals[$fqn]);
        }

        return $result;
    }

    public function enterNode(Node $node): ?int
    {
        $scope = $this->enterVisitorMethodContext($node);
        if ($scope !== null) {
            // Add +1 structural increment to parent method (SonarSource spec B1: lambdas)
            if ($scope->kind === \Qualimetrix\Core\Symbol\CallableKind::AnonymousCallable && $this->methodStack !== []) {
                $parentMethod = $this->methodStack[array_key_last($this->methodStack)];
                $parentFqn = $parentMethod['fqn'];
                $increment = 1 + $this->nestingLevel; // B1 + B3 nesting bonus
                $this->complexities[$parentFqn] = ($this->complexities[$parentFqn] ?? 0) + $increment;
                $this->increments[$parentFqn][] = [
                    'type' => 'closure',
                    'line' => $node->getStartLine(),
                    'points' => $increment,
                ];
            }

            $this->startMethod($scope);

            return null;
        }

        // Count complexity BEFORE incrementing nesting
        // This ensures we count the structure at its current nesting level
        $this->countComplexity($node);

        // Track node stack for tree-aware logical operator detection.
        // Push AFTER counting complexity so the current node is not in its own ancestor stack.
        $this->nodeStack[] = $node;

        // Increment nesting for nesting structures AFTER counting
        if ($this->isNestingStructure($node)) {
            ++$this->nestingLevel;
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        $scope = $this->leaveVisitorMethodContext($node);
        // Pop node stack only for nodes that were pushed (methods/functions/closures return
        // early in enterNode before the push, so they must not be popped here)
        if (!($node instanceof ClassMethod)
            && !($node instanceof Function_)
            && !($node instanceof PropertyHook)
            && !($node instanceof Closure)
            && !($node instanceof ArrowFunction)
        ) {
            array_pop($this->nodeStack);
        }

        // Decrement nesting for nesting structures
        if ($this->isNestingStructure($node)) {
            --$this->nestingLevel;
        }

        if ($scope !== null) {
            $this->endMethod($scope);
        }

        return null;
    }

    private function startMethod(VisitorCallableScope $scope): void
    {
        $fqn = $scope->traversalKey;
        // Save current nesting level and node stack before resetting (for closures/arrow functions inside nested scopes)
        $this->methodStack[] = [
            'fqn' => $fqn,
            'depth' => \count($this->methodStack),
            'nestingLevel' => $this->nestingLevel,
            'nodeStack' => $this->nodeStack,
        ];
        // Initialize with base complexity of 0 (unlike CCN which starts at 1)
        $this->complexities[$fqn] = 0;
        $this->increments[$fqn] = [];
        $this->scopes[$fqn] = $scope;
        // Reset nesting level and node stack for new method
        $this->nestingLevel = 0;
        $this->nodeStack = [];
    }

    private function endMethod(VisitorCallableScope $scope): void
    {
        $popped = array_pop($this->methodStack);

        // Restore outer method's nesting level and node stack
        if ($popped !== null) {
            $this->nestingLevel = $popped['nestingLevel'];
            $this->nodeStack = $popped['nodeStack'];
        }
    }

    /**
     * Checks if node is a nesting structure that increases nesting level.
     *
     * Note: ElseIf and Else are NOT nesting structures - they're at the same
     * level as their parent If. Only If increases nesting.
     */
    private function isNestingStructure(Node $node): bool
    {
        return $node instanceof If_
            || $node instanceof For_
            || $node instanceof Foreach_
            || $node instanceof While_
            || $node instanceof Do_
            || $node instanceof Catch_
            || $node instanceof Switch_
            || $node instanceof Match_;
    }

    private function countComplexity(Node $node): void
    {
        if ($this->methodStack === []) {
            return;
        }

        $increment = $this->getComplexityIncrement($node);

        if ($increment > 0) {
            $currentMethod = $this->methodStack[array_key_last($this->methodStack)];
            $fqn = $currentMethod['fqn'];
            $this->complexities[$fqn] += $increment;
            $this->increments[$fqn][] = [
                'type' => $this->getNodeTypeLabel($node),
                'line' => $node->getStartLine(),
                'points' => $increment,
            ];
        }
    }

    /**
     * Returns complexity increment for a given node.
     */
    private function getComplexityIncrement(Node $node): int
    {
        // Control flow structures with nesting bonus
        if ($node instanceof If_
            || $node instanceof For_
            || $node instanceof Foreach_
            || $node instanceof While_
            || $node instanceof Do_
            || $node instanceof Catch_
            || $node instanceof Switch_
            || $node instanceof Match_
        ) {
            return 1 + $this->nestingLevel;
        }

        // ElseIf: +1 structural increment only, NO nesting bonus per SonarSource spec (B1 only, not B3)
        if ($node instanceof ElseIf_) {
            return 1; // No nesting bonus per SonarSource spec (B1 only, not B3)
        }

        // Else: +1 structural increment only, NO nesting bonus per SonarSource spec (B1 only, not B3)
        if ($node instanceof Else_) {
            return 1;
        }

        // Ternary and null coalescing: +1 without nesting bonus
        if ($node instanceof Ternary || $node instanceof Coalesce) {
            return 1;
        }

        // Labeled jumps: +1 only (B1 fundamental increment, no nesting bonus per SonarSource spec)
        if ($node instanceof Goto_) {
            return 1;
        }

        if ($node instanceof Break_ && $node->num !== null) {
            return 1;
        }

        if ($node instanceof Continue_ && $node->num !== null) {
            return 1;
        }

        // Logical operators: count sequences using tree-aware parent detection
        if ($this->isLogicalOperator($node)) {
            return $this->getLogicalOperatorIncrement($node);
        }

        // Recursive calls: +1
        if ($this->isRecursiveCall($node)) {
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
            $node instanceof Coalesce => '??',
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
     * Checks if a call is recursive (calls the current method/function).
     */
    private function isRecursiveCall(Node $node): bool
    {
        if ($this->methodStack === []) {
            return false;
        }

        $currentMethod = $this->methodStack[array_key_last($this->methodStack)];
        $currentFqn = $currentMethod['fqn'];
        $info = $this->scopes[$currentFqn] ?? null;

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
