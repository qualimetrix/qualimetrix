<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Complexity;

use LogicException;
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
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Name;
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
 *   numbered break/continue, each sequence of like logical operators read in
 *   source order, and +1 once for a method that calls itself.
 * - B2 nesting level: raised inside the bodies of if, else if, else, ternary
 *   branches, switch, loops and catch, and inside lambdas. The whitepaper does
 *   not place conditions; here a condition or subject is read as not nested
 *   inside the structure it controls.
 * - B3 nesting increment: if, ternary, switch, loops and catch add the
 *   current nesting level on top of their +1.
 * - `else if` in two words is the whitepaper's hybrid `else if`, scored as
 *   `elseif`; an `if` inside a braced `else { }` is a nested if.
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
 * - A named function or an anonymous-class method declared inside a callable
 *   is measured as its own unit starting at nesting level 0. The whitepaper
 *   (B2) nests it one level inside the enclosing structure and adds its body
 *   to the enclosing method.
 * - Recursion is detected only as a direct self-call: `$this->m()` or
 *   `$this?->m()`, `self::`/`static::` or the own class name, or a function
 *   calling itself by its short, fully qualified or namespace-relative name.
 *   A cycle through other methods, and a call through a variable or a
 *   callable, is not detected.
 *
 * @see https://www.sonarsource.com/docs/CognitiveComplexity.pdf
 */
final class CognitiveComplexityVisitor extends NodeVisitorAbstract implements DeclarationIndexAwareInterface, ResettableVisitorInterface
{
    use VisitorMethodTrackingTrait;

    /** B1 + B3: +1 plus the current nesting level */
    private const NESTING_INCREMENT_STRUCTURES = [
        If_::class, For_::class, Foreach_::class, While_::class, Do_::class,
        Catch_::class, Switch_::class, Match_::class, Ternary::class,
    ];

    /** @var array<class-string<Node>, string> Breakdown label of each incrementing node type */
    private const INCREMENT_LABELS = [
        If_::class => 'if', ElseIf_::class => 'elseif', Else_::class => 'else',
        For_::class => 'for', Foreach_::class => 'foreach', While_::class => 'while', Do_::class => 'do',
        Catch_::class => 'catch', Switch_::class => 'switch', Match_::class => 'match', Ternary::class => 'ternary',
        Goto_::class => 'goto', Break_::class => 'break', Continue_::class => 'continue',
        BooleanAnd::class => '&&/||', LogicalAnd::class => '&&/||', BooleanOr::class => '&&/||', LogicalOr::class => '&&/||',
        FuncCall::class => 'recursion', MethodCall::class => 'recursion',
        NullsafeMethodCall::class => 'recursion', StaticCall::class => 'recursion',
    ];

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
     * @var list<array{unit: string, nestingLevel: int}>
     */
    private array $callableStack = [];

    /** @var int Current nesting level (0 = top level in method) */
    private int $nestingLevel = 0;

    /** @var SplObjectStorage<Node, int> Body nodes => nesting level they run at */
    private SplObjectStorage $bodyLevels;

    /** @var list<int> Nesting levels saved on entering a body node */
    private array $savedLevels = [];

    /** @var SplObjectStorage<Node, int> Logical operators of an entered sequence group => their increment */
    private SplObjectStorage $logicalIncrements;

    /** @var SplObjectStorage<Node, true> The else and the if of each `else if` */
    private SplObjectStorage $elseIfParts;

    public function __construct()
    {
        $this->bodyLevels = new SplObjectStorage();
        $this->logicalIncrements = new SplObjectStorage();
        $this->elseIfParts = new SplObjectStorage();
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
        $this->bodyLevels = new SplObjectStorage();
        $this->savedLevels = [];
        $this->logicalIncrements = new SplObjectStorage();
        $this->elseIfParts = new SplObjectStorage();
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

        if ($node instanceof If_) {
            $this->markElseIf($node);
        }

        $this->registerBodies($node);

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($this->leaveVisitorMethodContext($node) !== null) {
            $this->leaveCallable();
        }

        if ($this->bodyLevels->offsetExists($node)) {
            $this->bodyLevels->offsetUnset($node);
            $this->nestingLevel = array_pop($this->savedLevels) ?? 0;
        }

        $this->elseIfParts->offsetUnset($node);

        return null;
    }

    private function enterCallable(Node $node, VisitorCallableScope $scope): void
    {
        $isNestedLambda = $this->callableStack !== [] && ($node instanceof Closure || $node instanceof ArrowFunction);

        $this->callableStack[] = [
            'unit' => $scope->traversalKey,
            'nestingLevel' => $this->nestingLevel,
        ];
        $this->complexities[$scope->traversalKey] = 0;
        $this->increments[$scope->traversalKey] = [];
        $this->scopes[$scope->traversalKey] = $scope;
        $this->nestingLevel = $isNestedLambda ? $this->nestingLevel + 1 : 0;
    }

    private function leaveCallable(): void
    {
        $popped = array_pop($this->callableStack);

        if ($popped !== null) {
            $this->nestingLevel = $popped['nestingLevel'];
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
     * Marks an `else` whose only statement is an `if` written right after it:
     * `else if`, the whitepaper's hybrid increment, as PHP spells `elseif` in
     * two words. The parser drops the braces of `else { if ... }`, so the two
     * are told apart by where they end: an `else if` ends with its `if` and
     * with the `if` it continues, a braced or alternative-syntax `else` ends
     * after them.
     */
    private function markElseIf(If_ $node): void
    {
        $else = $node->else;
        if ($else === null || \count($else->stmts) !== 1 || !$else->stmts[0] instanceof If_) {
            return;
        }

        $inner = $else->stmts[0];
        $end = $this->endFilePos($node);
        if ($this->endFilePos($else) === $end && $this->endFilePos($inner) === $end) {
            $this->elseIfParts[$else] = true;
            $this->elseIfParts[$inner] = true;
        }
    }

    private function endFilePos(Node $node): int
    {
        if (!$node->hasAttribute('endFilePos')) {
            throw new LogicException('Cognitive complexity needs the parser\'s endFilePos attribute to tell `else if` from a nested if');
        }

        return $node->getEndFilePos();
    }

    /**
     * Marks the bodies a B2 structure nests: statements and branches, never
     * the condition or subject that controls them. The `if` of an `else if`
     * runs at the level of the chain it continues.
     */
    private function registerBodies(Node $node): void
    {
        $bodies = match (true) {
            $node instanceof Else_ => $this->elseIfParts->offsetExists($node) ? [] : $node->stmts,
            $node instanceof If_, $node instanceof ElseIf_, $node instanceof For_, $node instanceof Foreach_, $node instanceof While_,
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
        // B1 only for the two-word `else if`: its `if` carries the increment, its `else` none
        if ($this->elseIfParts->offsetExists($node)) {
            return $node instanceof If_ ? 1 : 0;
        }

        if (\in_array($node::class, self::NESTING_INCREMENT_STRUCTURES, true)) {
            return 1 + $this->nestingLevel;
        }

        if ($this->hasFlatIncrement($node)) {
            return 1;
        }

        if ($this->isLogicalOperator($node)) {
            return $this->getLogicalOperatorIncrement($node);
        }

        return $this->recursionIncrement($node, $unit);
    }

    /**
     * B1 only: elseif, else, goto and numbered jumps take no nesting increment.
     */
    private function hasFlatIncrement(Node $node): bool
    {
        return $node instanceof ElseIf_
            || $node instanceof Else_
            || $node instanceof Goto_
            || (($node instanceof Break_ || $node instanceof Continue_) && $node->num !== null);
    }

    private function recursionIncrement(Node $node, string $unit): int
    {
        if (isset($this->recursionCounted[$unit]) || !$this->isRecursiveCall($node, $unit)) {
            return 0;
        }

        $this->recursionCounted[$unit] = true;

        return 1;
    }

    private function isLogicalOperator(Node $node): bool
    {
        return $node instanceof BooleanAnd
            || $node instanceof LogicalAnd
            || $node instanceof BooleanOr
            || $node instanceof LogicalOr;
    }

    /**
     * B1 "sequences of binary logical operators": a group is a logical operator
     * whose parent is not one, together with the logical operators reached from
     * it through logical operators only. Read in source order, as the whitepaper
     * writes it, each operator that differs from the one before it starts a new
     * sequence: `$a && $b && $c || $d` is two, `$a && ($b || $c) && $d` is three.
     * Anything else in between — `!`, a call, an assignment, a ternary — closes
     * the group, and a logical operator below it starts a group of its own.
     */
    private function getLogicalOperatorIncrement(Node $node): int
    {
        if (!$this->logicalIncrements->offsetExists($node)) {
            $previous = null;
            foreach ($this->logicalOperatorsInSourceOrder($node) as $operator) {
                $this->logicalIncrements[$operator] = $previous !== null && $this->isSameLogicalOperatorType($previous, $operator) ? 0 : 1;
                $previous = $operator;
            }
        }

        $increment = $this->logicalIncrements[$node];
        $this->logicalIncrements->offsetUnset($node);

        return $increment;
    }

    /**
     * @return list<Node>
     */
    private function logicalOperatorsInSourceOrder(Node $node): array
    {
        if (!$node instanceof Node\Expr\BinaryOp || !$this->isLogicalOperator($node)) {
            return [];
        }

        return [
            ...$this->logicalOperatorsInSourceOrder($node->left),
            $node,
            ...$this->logicalOperatorsInSourceOrder($node->right),
        ];
    }

    /**
     * Returns a human-readable label for the node type used in breakdown messages.
     */
    private function getNodeTypeLabel(Node $node): string
    {
        if ($node instanceof If_ && $this->elseIfParts->offsetExists($node)) {
            return 'elseif';
        }

        return self::INCREMENT_LABELS[$node::class] ?? 'other';
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
     * A direct self-call, in every spelling PHP resolves to the unit itself:
     * names compare case-insensitively, a static call may name the own class,
     * and a function call may be fully qualified or namespace-relative.
     */
    private function isRecursiveCall(Node $node, string $unit): bool
    {
        $scope = $this->scopes[$unit] ?? null;
        if ($scope === null) {
            return false;
        }

        if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
            return $node->var instanceof Variable
                && $node->var->name === 'this'
                && $this->namesMember($node->name, $scope);
        }

        // parent:: calls the parent's method, which is not recursion
        if ($node instanceof StaticCall) {
            return $node->class instanceof Name
                && $this->namesOwnClass($node->class, $scope)
                && $this->namesMember($node->name, $scope);
        }

        // A function call inside a class method calls a function, never the method
        if ($node instanceof FuncCall) {
            return $scope->class === null
                && $node->name instanceof Name
                && $this->namesOwnFunction($node->name, $scope);
        }

        return false;
    }

    private function namesMember(Node $name, VisitorCallableScope $scope): bool
    {
        return $name instanceof Identifier && $name->toLowerString() === strtolower($scope->member);
    }

    private function namesOwnClass(Name $class, VisitorCallableScope $scope): bool
    {
        $lower = $class->toLowerString();
        if ($lower === 'self' || $lower === 'static') {
            return true;
        }

        if ($scope->class === null || $scope->anonymousClassContext) {
            return false;
        }

        return $class->isFullyQualified()
            ? $lower === strtolower($this->qualify($scope->namespace, $scope->class))
            : $class->isUnqualified() && $lower === strtolower($scope->class);
    }

    private function namesOwnFunction(Name $function, VisitorCallableScope $scope): bool
    {
        $lower = $function->toLowerString();

        if ($function->isFullyQualified()) {
            return $lower === strtolower($this->qualify($scope->namespace, $scope->member));
        }

        return ($function->isUnqualified() || $function->isRelative()) && $lower === strtolower($scope->member);
    }

    private function qualify(?string $namespace, string $name): string
    {
        return $namespace === null || $namespace === '' ? $name : $namespace . '\\' . $name;
    }
}
