<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Halstead;

use AiMessDetector\Core\Metric\MethodWithMetrics;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Metrics\ResettableVisitorInterface;
use AiMessDetector\Metrics\VisitorMethodTrackingTrait;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

/**
 * Halstead Complexity Metrics Visitor
 *
 * Implements a semantic approach to counting operators and operands.
 *
 * ## Methodology
 *
 * AIMD uses a **semantic approach** - counts only elements
 * that carry semantic meaning (operations on data), ignoring
 * syntactic "noise" (brackets, commas, semicolons).
 *
 * ### Operators (actions):
 * - Arithmetic: +, -, *, /, %, **
 * - Logical: &&, ||, !, and, or, xor
 * - Comparison: ==, ===, !=, !==, <, >, <=, >=, <=>
 * - Assignment: =, +=, -=, *=, /=, .=, ??=
 * - Bitwise: &, |, ^, ~, <<, >>
 * - Control flow: if, else, elseif, switch, case, for, foreach, while, do,
 *                 try, catch, throw, return, yield, break, continue
 * - Calls: ->, ::, ?->, new, clone, instanceof, call
 * - Arrays: [], array
 * - Type casts: (int), (string), (bool), (array), (object)
 * - Other: ?:, ??, @, print, empty, isset, eval, include, exit, list, match
 *
 * ### Operands (data):
 * - Variables: $var, $this
 * - Literals: numbers, strings, true, false, null
 * - Constants: CONST_NAME, self::CONST, ClassName::CONST
 * - Identifiers: function, method, class, and property names
 *
 * ### NOT counted (syntactic noise):
 * - Semicolons: ;
 * - Brackets: (, ), {, }, [, ]
 * - Commas: ,
 * - Colons in types: : int
 *
 * ## Differences from PDepend
 *
 * PDepend uses a token-oriented approach - counts all tokens,
 * including syntactic (, ), ;, ,. This leads to inflated metrics:
 * - Difficulty: +75-220%
 * - Effort: +100-350%
 *
 * AIMD follows the original Halstead methodology (1977), measuring
 * semantic complexity rather than syntactic density.
 *
 * @see HalsteadCollector
 * @see https://en.wikipedia.org/wiki/Halstead_complexity_measures
 */
final class HalsteadVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    use VisitorMethodTrackingTrait;

    /** @var array<string, HalsteadMetrics> FQN => metrics */
    private array $metrics = [];

    /** @var array<string, array{namespace: ?string, class: ?string, method: string, line: int, endLine: int, lloc: int}> */
    private array $methodInfos = [];

    /** @var list<array{fqn: string, operators: array<string, int>, operands: array<string, int>, codeLines: array<int, true>}> */
    private array $methodStack = [];

    private ?string $currentNamespace = null;
    private ?string $currentClass = null;
    private int $closureCounter = 0;

    /** @var int Depth of anonymous class nesting (methods inside anonymous classes are skipped) */
    private int $anonymousClassDepth = 0;

    public function reset(): void
    {
        $this->metrics = [];
        $this->methodInfos = [];
        $this->methodStack = [];
        $this->currentNamespace = null;
        $this->currentClass = null;
        $this->closureCounter = 0;
        $this->anonymousClassDepth = 0;
    }

    /**
     * @return array<string, HalsteadMetrics>
     */
    public function getMetrics(): array
    {
        return $this->metrics;
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
            $halstead = $this->metrics[$fqn] ?? HalsteadMetrics::empty();

            // Use LLOC (logical lines — statement count) for accurate MI calculation.
            // Falls back to physical LOC only if no statements were counted.
            $methodLoc = $info['lloc'] > 0
                ? $info['lloc']
                : max(1, $info['endLine'] - $info['line'] + 1);

            $bag = (new MetricBag())
                ->with('halstead.volume', $halstead->volume())
                ->with('halstead.difficulty', $halstead->difficulty())
                ->with('halstead.effort', $halstead->effort())
                ->with('halstead.bugs', $halstead->bugs())
                ->with('halstead.time', $halstead->time())
                ->with('methodLoc', $methodLoc);

            $result[] = new MethodWithMetrics(
                namespace: $info['namespace'],
                class: $info['class'],
                method: $info['method'],
                line: $info['line'],
                metrics: $bag,
            );
        }

        return $result;
    }

    public function enterNode(Node $node): ?int
    {
        // Track namespace
        if ($node instanceof Stmt\Namespace_) {
            $this->currentNamespace = $node->name?->toString() ?? '';
        }

        // Track class-like types (skip anonymous classes)
        if ($node instanceof Stmt\Class_) {
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
        if ($node instanceof Stmt\ClassMethod) {
            if ($this->anonymousClassDepth === 0) {
                $fqn = $this->buildMethodFqn($node->name->toString());
                $this->startMethod($fqn, $node->name->toString(), $node->getStartLine());
            }

            return null;
        }

        // Start of a function
        if ($node instanceof Stmt\Function_) {
            $fqn = $this->buildFunctionFqn($node->name->toString());
            $this->startMethod($fqn, $node->name->toString(), $node->getStartLine());

            return null;
        }

        // Start of a closure
        if ($node instanceof Expr\Closure) {
            ++$this->closureCounter;
            $fqn = $this->buildClosureFqn();
            $closureName = '{closure#' . $this->closureCounter . '}';
            $this->startMethod($fqn, $closureName, $node->getStartLine());

            return null;
        }

        // Arrow function
        if ($node instanceof Expr\ArrowFunction) {
            ++$this->closureCounter;
            $fqn = $this->buildClosureFqn();
            $closureName = '{closure#' . $this->closureCounter . '}';
            $this->startMethod($fqn, $closureName, $node->getStartLine());

            return null;
        }

        // Track code lines and count operators/operands (skip anonymous class internals)
        if ($this->methodStack !== [] && $this->anonymousClassDepth === 0) {
            $idx = array_key_last($this->methodStack);
            if ($idx !== null) {
                $this->methodStack[$idx]['codeLines'][$node->getStartLine()] = true;
            }
            $this->countOperators($node);
            $this->countOperands($node);
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        // End of method (skip if inside anonymous class — we didn't start it)
        if ($node instanceof Stmt\ClassMethod) {
            if ($this->anonymousClassDepth === 0) {
                $this->endMethod($node->getEndLine());
            }

            return null;
        }

        // End of function/closure/arrow function
        if ($node instanceof Stmt\Function_
            || $node instanceof Expr\Closure
            || $node instanceof Expr\ArrowFunction
        ) {
            $this->endMethod($node->getEndLine());

            return null;
        }

        // Exit class-like scope
        if ($node instanceof Stmt\Class_) {
            if ($node->name === null) {
                --$this->anonymousClassDepth;
            } else {
                $this->currentClass = null;
            }
        } elseif ($this->isClassLikeNode($node)) {
            $this->currentClass = null;
        }

        // Exit namespace scope
        if ($node instanceof Stmt\Namespace_) {
            $this->currentNamespace = null;
        }

        return null;
    }

    private function startMethod(string $fqn, string $methodName, int $line): void
    {
        $this->methodStack[] = [
            'fqn' => $fqn,
            'operators' => [],
            'operands' => [],
            'codeLines' => [],
        ];

        $this->methodInfos[$fqn] = [
            'namespace' => $this->currentNamespace,
            'class' => $this->currentClass,
            'method' => $methodName,
            'line' => $line,
            'endLine' => $line, // Will be updated in endMethod
            'lloc' => 0,
        ];
    }

    private function endMethod(int $endLine): void
    {
        if ($this->methodStack === []) {
            return;
        }

        $current = array_pop($this->methodStack);
        $fqn = $current['fqn'];
        $operators = $current['operators'];
        $operands = $current['operands'];

        // Update endLine and LLOC (executable lines of code) in methodInfos
        if (isset($this->methodInfos[$fqn])) {
            $this->methodInfos[$fqn]['endLine'] = $endLine;
            $this->methodInfos[$fqn]['lloc'] = \count($current['codeLines']);
        }

        $n1 = \count($operators);  // unique operators
        $n2 = \count($operands);   // unique operands
        $N1 = array_sum($operators);  // total operators
        $N2 = array_sum($operands);   // total operands

        $this->metrics[$fqn] = new HalsteadMetrics($n1, $n2, (int) $N1, (int) $N2);
    }

    private function countOperators(Node $node): void
    {
        $operator = $this->getOperatorName($node);

        if ($operator === null) {
            return;
        }

        $idx = array_key_last($this->methodStack);
        if ($idx === null) {
            return;
        }
        /** @var int $count */
        $count = $this->methodStack[$idx]['operators'][$operator] ?? 0;
        $this->methodStack[$idx]['operators'][$operator] = $count + 1;
    }

    private function countOperands(Node $node): void
    {
        $operand = $this->getOperandName($node);

        if ($operand === null) {
            return;
        }

        $idx = array_key_last($this->methodStack);
        if ($idx === null) {
            return;
        }
        /** @var int $count */
        $count = $this->methodStack[$idx]['operands'][$operand] ?? 0;
        $this->methodStack[$idx]['operands'][$operand] = $count + 1;
    }

    /**
     * Returns operator name for operator nodes, null otherwise.
     */
    private function getOperatorName(Node $node): ?string
    {
        // Binary operators
        if ($node instanceof Expr\BinaryOp) {
            return $this->getBinaryOpName($node);
        }

        // Check each category in sequence
        return $this->getUnaryOpName($node)
            ?? $this->getAssignmentOpName($node)
            ?? $this->getControlFlowOpName($node)
            ?? $this->getCallOpName($node)
            ?? $this->getArrayOpName($node)
            ?? $this->getCastOpName($node)
            ?? $this->getOtherOpName($node);
    }

    private function getUnaryOpName(Node $node): ?string
    {
        return match (true) {
            $node instanceof Expr\UnaryMinus => '-',
            $node instanceof Expr\UnaryPlus => '+',
            $node instanceof Expr\BitwiseNot => '~',
            $node instanceof Expr\BooleanNot => '!',
            $node instanceof Expr\PreInc, $node instanceof Expr\PostInc => '++',
            $node instanceof Expr\PreDec, $node instanceof Expr\PostDec => '--',
            $node instanceof Expr\ErrorSuppress => '@',
            default => null,
        };
    }

    private function getAssignmentOpName(Node $node): ?string
    {
        return match (true) {
            $node instanceof Expr\Assign => '=',
            $node instanceof Expr\AssignOp => $this->getAssignOpName($node),
            $node instanceof Expr\AssignRef => '=&',
            $node instanceof Expr\Ternary => '?:',
            default => null,
        };
    }

    private function getControlFlowOpName(Node $node): ?string
    {
        return match (true) {
            $node instanceof Stmt\If_ => 'if',
            $node instanceof Stmt\Else_ => 'else',
            $node instanceof Stmt\ElseIf_ => 'elseif',
            $node instanceof Stmt\Switch_ => 'switch',
            $node instanceof Stmt\Case_ => 'case',
            $node instanceof Stmt\For_ => 'for',
            $node instanceof Stmt\Foreach_ => 'foreach',
            $node instanceof Stmt\While_ => 'while',
            $node instanceof Stmt\Do_ => 'do',
            $node instanceof Stmt\TryCatch => 'try',
            $node instanceof Stmt\Catch_ => 'catch',
            $node instanceof Stmt\Finally_ => 'finally',
            $node instanceof Expr\Throw_ => 'throw',
            $node instanceof Stmt\Return_ => 'return',
            $node instanceof Expr\Yield_ => 'yield',
            $node instanceof Expr\YieldFrom => 'yield from',
            $node instanceof Stmt\Break_ => 'break',
            $node instanceof Stmt\Continue_ => 'continue',
            $node instanceof Stmt\Goto_ => 'goto',
            default => null,
        };
    }

    private function getCallOpName(Node $node): ?string
    {
        return match (true) {
            $node instanceof Expr\MethodCall => '->',
            $node instanceof Expr\PropertyFetch => '->',
            $node instanceof Expr\StaticCall => '::',
            $node instanceof Expr\StaticPropertyFetch => '::',
            $node instanceof Expr\ClassConstFetch => '::',
            $node instanceof Expr\NullsafeMethodCall => '?->',
            $node instanceof Expr\NullsafePropertyFetch => '?->',
            $node instanceof Expr\FuncCall => 'call',
            $node instanceof Expr\New_ => 'new',
            $node instanceof Expr\Clone_ => 'clone',
            $node instanceof Expr\Instanceof_ => 'instanceof',
            default => null,
        };
    }

    private function getArrayOpName(Node $node): ?string
    {
        return match (true) {
            $node instanceof Expr\ArrayDimFetch => '[]',
            $node instanceof Expr\Array_ => 'array',
            default => null,
        };
    }

    private function getCastOpName(Node $node): ?string
    {
        if ($node instanceof Expr\Cast) {
            return $this->getCastName($node);
        }

        return null;
    }

    private function getOtherOpName(Node $node): ?string
    {
        return match (true) {
            $node instanceof Expr\Print_ => 'print',
            $node instanceof Expr\Empty_ => 'empty',
            $node instanceof Expr\Isset_ => 'isset',
            $node instanceof Expr\Eval_ => 'eval',
            $node instanceof Expr\Include_ => 'include',
            $node instanceof Expr\Exit_ => 'exit',
            $node instanceof Expr\List_ => 'list',
            $node instanceof Expr\Match_ => 'match',
            default => null,
        };
    }

    private function getBinaryOpName(Expr\BinaryOp $node): string
    {
        return match (true) {
            $node instanceof Expr\BinaryOp\Plus => '+',
            $node instanceof Expr\BinaryOp\Minus => '-',
            $node instanceof Expr\BinaryOp\Mul => '*',
            $node instanceof Expr\BinaryOp\Div => '/',
            $node instanceof Expr\BinaryOp\Mod => '%',
            $node instanceof Expr\BinaryOp\Pow => '**',
            $node instanceof Expr\BinaryOp\Concat => '.',
            $node instanceof Expr\BinaryOp\BooleanAnd => '&&',
            $node instanceof Expr\BinaryOp\BooleanOr => '||',
            $node instanceof Expr\BinaryOp\LogicalAnd => 'and',
            $node instanceof Expr\BinaryOp\LogicalOr => 'or',
            $node instanceof Expr\BinaryOp\LogicalXor => 'xor',
            $node instanceof Expr\BinaryOp\BitwiseAnd => '&',
            $node instanceof Expr\BinaryOp\BitwiseOr => '|',
            $node instanceof Expr\BinaryOp\BitwiseXor => '^',
            $node instanceof Expr\BinaryOp\ShiftLeft => '<<',
            $node instanceof Expr\BinaryOp\ShiftRight => '>>',
            $node instanceof Expr\BinaryOp\Equal => '==',
            $node instanceof Expr\BinaryOp\NotEqual => '!=',
            $node instanceof Expr\BinaryOp\Identical => '===',
            $node instanceof Expr\BinaryOp\NotIdentical => '!==',
            $node instanceof Expr\BinaryOp\Smaller => '<',
            $node instanceof Expr\BinaryOp\SmallerOrEqual => '<=',
            $node instanceof Expr\BinaryOp\Greater => '>',
            $node instanceof Expr\BinaryOp\GreaterOrEqual => '>=',
            $node instanceof Expr\BinaryOp\Spaceship => '<=>',
            $node instanceof Expr\BinaryOp\Coalesce => '??',
            default => 'binary_op',
        };
    }

    private function getAssignOpName(Expr\AssignOp $node): string
    {
        return match (true) {
            $node instanceof Expr\AssignOp\Plus => '+=',
            $node instanceof Expr\AssignOp\Minus => '-=',
            $node instanceof Expr\AssignOp\Mul => '*=',
            $node instanceof Expr\AssignOp\Div => '/=',
            $node instanceof Expr\AssignOp\Mod => '%=',
            $node instanceof Expr\AssignOp\Pow => '**=',
            $node instanceof Expr\AssignOp\Concat => '.=',
            $node instanceof Expr\AssignOp\BitwiseAnd => '&=',
            $node instanceof Expr\AssignOp\BitwiseOr => '|=',
            $node instanceof Expr\AssignOp\BitwiseXor => '^=',
            $node instanceof Expr\AssignOp\ShiftLeft => '<<=',
            $node instanceof Expr\AssignOp\ShiftRight => '>>=',
            $node instanceof Expr\AssignOp\Coalesce => '??=',
            default => 'assign_op',
        };
    }

    private function getCastName(Expr\Cast $node): string
    {
        return match (true) {
            $node instanceof Expr\Cast\Int_ => '(int)',
            $node instanceof Expr\Cast\Double => '(float)',
            $node instanceof Expr\Cast\String_ => '(string)',
            $node instanceof Expr\Cast\Bool_ => '(bool)',
            $node instanceof Expr\Cast\Array_ => '(array)',
            $node instanceof Expr\Cast\Object_ => '(object)',
            $node instanceof Expr\Cast\Unset_ => '(unset)',
            default => '(cast)',
        };
    }

    /**
     * Returns operand identifier for operand nodes, null otherwise.
     */
    private function getOperandName(Node $node): ?string
    {
        // Variables
        if ($node instanceof Expr\Variable) {
            if (\is_string($node->name)) {
                return '$' . $node->name;
            }

            // Variable variable ($$var) - count as single operand
            return '$var';
        }

        // Constants
        if ($node instanceof Node\Scalar\Int_ || $node instanceof Node\Scalar\Float_) {
            return 'num:' . $node->value;
        }
        if ($node instanceof Node\Scalar\String_) {
            // Normalize strings to avoid counting each unique string as different operand
            return 'str:' . md5($node->value);
        }
        if ($node instanceof Node\Scalar\InterpolatedString) {
            return 'str:interpolated';
        }
        if ($node instanceof Expr\ConstFetch) {
            return 'const:' . $node->name->toString();
        }

        // Class constants (the constant name is the operand, not the ::)
        if ($node instanceof Expr\ClassConstFetch && $node->name instanceof Node\Identifier) {
            return 'classconst:' . $node->name->toString();
        }

        // Property access (the property name is the operand, not the ->)
        if ($node instanceof Expr\PropertyFetch && $node->name instanceof Node\Identifier) {
            return 'prop:' . $node->name->toString();
        }
        if ($node instanceof Expr\StaticPropertyFetch && $node->name instanceof Node\VarLikeIdentifier) {
            return 'prop:' . $node->name->toString();
        }
        if ($node instanceof Expr\NullsafePropertyFetch && $node->name instanceof Node\Identifier) {
            return 'prop:' . $node->name->toString();
        }

        // Method/function names (the name being called)
        if ($node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier) {
            return 'method:' . $node->name->toString();
        }
        if ($node instanceof Expr\NullsafeMethodCall && $node->name instanceof Node\Identifier) {
            return 'method:' . $node->name->toString();
        }
        if ($node instanceof Expr\StaticCall && $node->name instanceof Node\Identifier) {
            return 'method:' . $node->name->toString();
        }
        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
            return 'func:' . $node->name->toString();
        }

        // Class names in new expressions
        if ($node instanceof Expr\New_ && $node->class instanceof Node\Name) {
            return 'class:' . $node->class->toString();
        }

        return null;
    }

}
