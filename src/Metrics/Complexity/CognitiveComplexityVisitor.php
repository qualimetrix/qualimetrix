<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Complexity;

use AiMessDetector\Core\Metric\MethodWithMetrics;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Metrics\ResettableVisitorInterface;
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
 * - Goto, labeled break/continue: +1 + nesting level
 * - Ternary, null coalescing: +1 (no nesting bonus)
 * - Recursion: +1
 *
 * @see https://www.sonarsource.com/docs/CognitiveComplexity.pdf
 */
final class CognitiveComplexityVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    /** @var array<string, int> Method/function FQN => complexity */
    private array $complexities = [];

    /** @var array<string, array{namespace: ?string, class: ?string, method: string, line: int}> FQN => method info */
    private array $methodInfos = [];

    /** @var list<array{fqn: string, depth: int}> Stack of nested methods/functions */
    private array $methodStack = [];

    /** @var int Current nesting level (0 = top level in method) */
    private int $nestingLevel = 0;

    private ?string $currentNamespace = null;
    private ?string $currentClass = null;
    private int $closureCounter = 0;

    /** @var int Depth of anonymous class nesting (methods inside anonymous classes are skipped) */
    private int $anonymousClassDepth = 0;

    /** @var array<string, string> Track last logical operator per context for sequence detection */
    private array $lastLogicalOp = [];

    public function reset(): void
    {
        $this->complexities = [];
        $this->methodInfos = [];
        $this->methodStack = [];
        $this->nestingLevel = 0;
        $this->currentNamespace = null;
        $this->currentClass = null;
        $this->closureCounter = 0;
        $this->anonymousClassDepth = 0;
        $this->lastLogicalOp = [];
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
            $metrics = (new MetricBag())->with('cognitive', $this->complexities[$fqn] ?? 0);

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
                // Anonymous class - increment depth to skip methods inside
                ++$this->anonymousClassDepth;
            } else {
                // Named class - track it
                $this->currentClass = $node->name->toString();
            }
        } elseif ($this->isClassLikeNode($node)) {
            // Interface, Trait, Enum (always named)
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

            // Note: closures start fresh - nesting is already reset in startMethod()

            return null;
        }

        // Count complexity BEFORE incrementing nesting
        // This ensures we count the structure at its current nesting level
        $this->countComplexity($node);

        // Increment nesting for nesting structures AFTER counting
        if ($this->isNestingStructure($node)) {
            ++$this->nestingLevel;
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        // Decrement nesting for nesting structures
        if ($this->isNestingStructure($node)) {
            --$this->nestingLevel;
        }

        // End of method/function
        if ($node instanceof ClassMethod || $node instanceof Function_) {
            $this->endMethod();

            return null;
        }

        // End of closure/arrow function
        if ($node instanceof Closure || $node instanceof ArrowFunction) {
            $this->endMethod();

            return null;
        }

        // Exit class-like scope
        if ($node instanceof Node\Stmt\Class_) {
            if ($node->name === null) {
                // Anonymous class - decrement depth
                --$this->anonymousClassDepth;
            } else {
                // Named class - reset current class
                $this->currentClass = null;
            }
        } elseif ($this->isClassLikeNode($node)) {
            // Interface, Trait, Enum
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
        // Initialize with base complexity of 0 (unlike CCN which starts at 1)
        $this->complexities[$fqn] = 0;
        // Store method info for later retrieval
        $this->methodInfos[$fqn] = [
            'namespace' => $this->currentNamespace,
            'class' => $this->currentClass,
            'method' => $methodName,
            'line' => $line,
        ];
        // Reset nesting level for new method
        $this->nestingLevel = 0;
        // Reset logical operator tracking for new method
        unset($this->lastLogicalOp[$fqn]);
    }

    private function endMethod(): void
    {
        array_pop($this->methodStack);
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

    /**
     * Extracts class name from class-like nodes (class, interface, trait, enum).
     * Returns null for anonymous classes or non-class-like nodes.
     */
    private function extractClassLikeName(Node $node): ?string
    {
        return match (true) {
            $node instanceof Node\Stmt\Class_ && $node->name !== null => $node->name->toString(),
            $node instanceof Node\Stmt\Interface_ && $node->name !== null => $node->name->toString(),
            $node instanceof Node\Stmt\Trait_ && $node->name !== null => $node->name->toString(),
            $node instanceof Node\Stmt\Enum_ && $node->name !== null => $node->name->toString(),
            default => null,
        };
    }

    /**
     * Checks if node is a class-like type (class, interface, trait, enum).
     */
    private function isClassLikeNode(Node $node): bool
    {
        return $node instanceof Node\Stmt\Class_
            || $node instanceof Node\Stmt\Interface_
            || $node instanceof Node\Stmt\Trait_
            || $node instanceof Node\Stmt\Enum_;
    }

    private function countComplexity(Node $node): void
    {
        if ($this->methodStack === []) {
            return;
        }

        // Don't count complexity inside anonymous classes
        if ($this->anonymousClassDepth > 0) {
            return;
        }

        $increment = $this->getComplexityIncrement($node);

        if ($increment > 0) {
            $currentMethod = $this->methodStack[array_key_last($this->methodStack)];
            $this->complexities[$currentMethod['fqn']] += $increment;
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

        // ElseIf and Else: +1 with nesting bonus (same level as parent If)
        // They GET the nesting bonus but don't INCREASE nesting
        // Since they're processed INSIDE the If_ node (before leaveNode),
        // we need to use nestingLevel - 1 to account for parent If's increment
        if ($node instanceof ElseIf_ || $node instanceof Else_) {
            return 1 + max(0, $this->nestingLevel - 1);
        }

        // Ternary and null coalescing: +1 without nesting bonus
        if ($node instanceof Ternary || $node instanceof Coalesce) {
            return 1;
        }

        // Labeled jumps: +1 + nesting
        if ($node instanceof Goto_) {
            return 1 + $this->nestingLevel;
        }

        if ($node instanceof Break_ && $node->num !== null) {
            return 1 + $this->nestingLevel;
        }

        if ($node instanceof Continue_ && $node->num !== null) {
            return 1 + $this->nestingLevel;
        }

        // Logical operators: count sequences
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
     * Calculates increment for logical operators.
     *
     * Sequences of same operator count as 1, changing operator adds 1.
     * Example: "a && b && c" = +1, "a && b || c" = +2
     */
    private function getLogicalOperatorIncrement(Node $node): int
    {
        if ($this->methodStack === []) {
            return 0;
        }

        $currentMethod = $this->methodStack[array_key_last($this->methodStack)];
        $contextKey = $currentMethod['fqn'] . ':' . spl_object_id($node);

        $opType = match (true) {
            $node instanceof BooleanAnd, $node instanceof LogicalAnd => 'and',
            $node instanceof BooleanOr, $node instanceof LogicalOr => 'or',
            default => 'unknown',
        };

        // Check if we're in a sequence of same operator
        $lastOp = $this->lastLogicalOp[$currentMethod['fqn']] ?? null;

        $this->lastLogicalOp[$currentMethod['fqn']] = $opType;

        // First logical operator in sequence: +1
        if ($lastOp === null || $lastOp !== $opType) {
            return 1;
        }

        // Same operator in sequence: +0 (already counted)
        return 0;
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
        $info = $this->methodInfos[$currentFqn] ?? null;

        if ($info === null) {
            return false;
        }

        $methodName = $info['method'];

        // Check for method call recursion
        if ($node instanceof MethodCall || $node instanceof StaticCall) {
            $calledMethod = $node->name instanceof Node\Identifier
                ? $node->name->toString()
                : null;

            return $calledMethod === $methodName;
        }

        // Check for function call recursion
        if ($node instanceof FuncCall && $node->name instanceof Node\Name) {
            $calledFunction = $node->name->toString();

            // For namespaced functions, compare short name
            if (str_contains($calledFunction, '\\')) {
                $calledFunction = substr($calledFunction, strrpos($calledFunction, '\\') + 1);
            }

            return $calledFunction === $methodName;
        }

        return false;
    }

    private function buildMethodFqn(string $methodName): string
    {
        $parts = [];

        if ($this->currentNamespace !== null && $this->currentNamespace !== '') {
            $parts[] = $this->currentNamespace;
        }

        if ($this->currentClass !== null) {
            if ($parts !== []) {
                $parts[] = '\\';
            }
            $parts[] = $this->currentClass;
        }

        $parts[] = '::';
        $parts[] = $methodName;

        return implode('', $parts);
    }

    private function buildFunctionFqn(string $functionName): string
    {
        if ($this->currentNamespace !== null && $this->currentNamespace !== '') {
            return $this->currentNamespace . '\\' . $functionName;
        }

        return $functionName;
    }

    private function buildClosureFqn(): string
    {
        $parts = [];

        if ($this->currentNamespace !== null && $this->currentNamespace !== '') {
            $parts[] = $this->currentNamespace;
        }

        if ($this->currentClass !== null) {
            if ($parts !== []) {
                $parts[] = '\\';
            }
            $parts[] = $this->currentClass;
        }

        $parts[] = '::';
        $parts[] = '{closure#' . $this->closureCounter . '}';

        return implode('', $parts);
    }
}
