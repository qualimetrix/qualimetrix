<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\CodeSmell;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\ErrorSuppress;
use PhpParser\Node\Expr\Eval_;
use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Goto_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Metrics\ResettableVisitorInterface;

/**
 * Visitor for detecting code smells in AST.
 *
 * Detects:
 * - goto statements
 * - eval() expressions
 * - exit()/die() expressions
 * - Empty catch blocks
 * - Debug code (var_dump, print_r, dd, dump, debug_print_backtrace)
 * - Error suppression operator (@)
 * - count() in loop conditions
 * - Direct superglobal access ($_GET, $_POST, etc.)
 * - Boolean argument flags in method/function parameters
 */
final class CodeSmellVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    private const DEBUG_FUNCTIONS = [
        'var_dump',
        'print_r',
        'var_export',
        'dd',
        'dump',
        'debug_print_backtrace',
    ];

    /**
     * Method names that are part of a debugging API (e.g., Dumpable::dump(), dd()).
     * Debug function calls inside these methods are intentional, not leftover debug code.
     */
    private const DEBUG_API_METHOD_NAMES = [
        'dump',
        'dd',
        'debug',
        'dumprawsql',
        'dumpsql',
        'debuginfo',
        '__debuginfo',
    ];

    private const SUPERGLOBALS = [
        '_GET',
        '_POST',
        '_REQUEST',
        '_COOKIE',
        '_SESSION',
        '_SERVER',
        '_FILES',
        '_ENV',
        'GLOBALS',
    ];

    /** @var list<CodeSmellLocation> */
    private array $locations = [];

    /** @var list<string> Stack of enclosing method/function names (lowercase) */
    private array $methodStack = [];

    /** Depth of nested foreach loops (for chain-of-responsibility pattern detection) */
    private int $foreachDepth = 0;

    public function reset(): void
    {
        $this->locations = [];
        $this->methodStack = [];
        $this->foreachDepth = 0;
    }

    public function enterNode(Node $node): ?int
    {
        // Context tracking (always runs, independent of smell detection)
        if ($node instanceof ClassMethod || $node instanceof Function_) {
            $this->methodStack[] = $node->name->toLowerString();
            $this->checkBooleanArgument($node);
        } elseif ($node instanceof Closure || $node instanceof ArrowFunction) {
            $this->checkBooleanArgument($node);
        }

        if ($node instanceof Foreach_) {
            $this->foreachDepth++;
        }

        // Smell detection — node types are mutually exclusive, no early returns needed
        if ($node instanceof Goto_) {
            $this->addLocation('goto', $node);
        } elseif ($node instanceof Eval_) {
            $this->addLocation('eval', $node);
        } elseif ($node instanceof Exit_) {
            $this->addLocation('exit', $node);
        } elseif ($node instanceof TryCatch) {
            $this->checkEmptyCatches($node);
        } elseif ($node instanceof FuncCall) {
            $this->checkDebugFunction($node);
        } elseif ($node instanceof ErrorSuppress) {
            $funcName = null;
            if ($node->expr instanceof FuncCall && $node->expr->name instanceof Name) {
                $funcName = $node->expr->name->toLowerString();
            }
            $this->addLocation('error_suppression', $node, $funcName);
        } elseif ($node instanceof For_ || $node instanceof While_ || $node instanceof Do_) {
            $this->checkCountInLoop($node);
        } elseif ($node instanceof Variable) {
            $this->checkSuperglobal($node);
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof ClassMethod || $node instanceof Function_) {
            array_pop($this->methodStack);
        }

        if ($node instanceof Foreach_) {
            $this->foreachDepth--;
        }

        return null;
    }

    /**
     * @return list<CodeSmellLocation>
     */
    public function getLocations(): array
    {
        return $this->locations;
    }

    /**
     * @return list<CodeSmellLocation>
     */
    public function getLocationsByType(string $type): array
    {
        return array_values(
            array_filter(
                $this->locations,
                static fn(CodeSmellLocation $loc): bool => $loc->type === $type,
            ),
        );
    }

    public function getCountByType(string $type): int
    {
        return \count($this->getLocationsByType($type));
    }

    private function addLocation(string $type, Node $node, ?string $extra = null): void
    {
        $this->locations[] = new CodeSmellLocation(
            type: $type,
            line: $node->getStartLine(),
            column: $node->getStartTokenPos(),
            extra: $extra,
        );
    }

    private function checkEmptyCatches(TryCatch $tryCatch): void
    {
        foreach ($tryCatch->catches as $catch) {
            $this->checkEmptyCatch($catch, $tryCatch);
        }
    }

    private function checkEmptyCatch(Catch_ $node, TryCatch $tryCatch): void
    {
        // Empty catch block = no statements (Nop nodes are comment-only placeholders)
        $realStmts = array_filter($node->stmts, static fn(Node $s): bool => !$s instanceof Node\Stmt\Nop);

        if ($realStmts !== []) {
            return;
        }

        // Chain-of-responsibility pattern: foreach + try { return } catch { }
        // This is a legitimate pattern where each handler is tried and failures are caught
        if ($this->foreachDepth > 0 && $this->tryBlockContainsReturn($tryCatch)) {
            return;
        }

        $this->addLocation('empty_catch', $node);
    }

    /**
     * Check if the try block contains a return, continue, or yield statement.
     * Checks recursively into if/else blocks but not into closures/loops.
     */
    private function tryBlockContainsReturn(TryCatch $tryCatch): bool
    {
        return $this->statementsContainChainSignal($tryCatch->stmts);
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $stmts
     */
    private function statementsContainChainSignal(array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Return_ || $stmt instanceof \PhpParser\Node\Stmt\Continue_) {
                return true;
            }

            // Check inside if/elseif/else blocks
            if ($stmt instanceof \PhpParser\Node\Stmt\If_) {
                if ($this->statementsContainChainSignal($stmt->stmts)) {
                    return true;
                }
                foreach ($stmt->elseifs as $elseif) {
                    if ($this->statementsContainChainSignal($elseif->stmts)) {
                        return true;
                    }
                }
                if ($stmt->else !== null && $this->statementsContainChainSignal($stmt->else->stmts)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function checkDebugFunction(FuncCall $node): void
    {
        if (!$node->name instanceof Name) {
            return;
        }

        $functionName = $node->name->toLowerString();

        if (!\in_array($functionName, self::DEBUG_FUNCTIONS, true)) {
            return;
        }

        // var_export()/print_r() with 2nd argument `true` is return mode (not output), not debug code
        if (($functionName === 'var_export' || $functionName === 'print_r') && $this->isReturnModeCall($node)) {
            return;
        }

        // Debug calls inside debug API methods (e.g., Dumpable::dump()) are intentional
        if ($this->isInsideDebugApiMethod()) {
            return;
        }

        $this->addLocation('debug_code', $node, $functionName);
    }

    /**
     * Check if var_export()/print_r() is called with 2nd argument `true` (return mode).
     * Also handles named arguments: var_export(return: true).
     */
    private function isReturnModeCall(FuncCall $node): bool
    {
        $args = $node->getArgs();

        foreach ($args as $arg) {
            // Named argument: var_export(return: true) or print_r(return: true)
            if ($arg->name !== null && $arg->name->toString() === 'return') {
                return $arg->value instanceof ConstFetch
                    && $arg->value->name->toLowerString() === 'true';
            }
        }

        // Positional: 2nd argument is `true`
        if (\count($args) < 2) {
            return false;
        }

        $secondArg = $args[1]->value;

        return $secondArg instanceof ConstFetch
            && $secondArg->name->toLowerString() === 'true';
    }

    /**
     * Check if current context is inside a debug API method (dump, dd, debug, etc.).
     */
    private function isInsideDebugApiMethod(): bool
    {
        if ($this->methodStack === []) {
            return false;
        }

        $currentMethod = $this->methodStack[\count($this->methodStack) - 1];

        return \in_array($currentMethod, self::DEBUG_API_METHOD_NAMES, true);
    }

    private function checkCountInLoop(For_|While_|Do_ $node): void
    {
        $conditions = match (true) {
            $node instanceof For_ => $node->cond,
            $node instanceof While_ => [$node->cond],
            $node instanceof Do_ => [$node->cond],
        };

        foreach ($conditions as $condition) {
            if ($this->containsCountCall($condition)) {
                $this->addLocation('count_in_loop', $node);

                return;
            }
        }
    }

    private function containsCountCall(?Node $node): bool
    {
        if ($node === null) {
            return false;
        }

        if ($node instanceof FuncCall
            && $node->name instanceof Name
            && \in_array($node->name->toLowerString(), ['count', 'sizeof'], true)
        ) {
            return true;
        }

        // Check nested expressions
        foreach ($node->getSubNodeNames() as $name) {
            $subNode = $node->{$name};

            // Skip closures and arrow functions — count() inside them is not in the loop condition
            if ($subNode instanceof Closure || $subNode instanceof ArrowFunction) {
                continue;
            }

            if ($subNode instanceof Node && $this->containsCountCall($subNode)) {
                return true;
            }

            if (\is_array($subNode)) {
                foreach ($subNode as $item) {
                    // Skip closures and arrow functions
                    if ($item instanceof Closure || $item instanceof ArrowFunction) {
                        continue;
                    }

                    if ($item instanceof Node && $this->containsCountCall($item)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function checkSuperglobal(Variable $node): void
    {
        if (!\is_string($node->name)) {
            return;
        }

        if (\in_array($node->name, self::SUPERGLOBALS, true)) {
            $this->addLocation('superglobals', $node, $node->name);
        }
    }

    private function checkBooleanArgument(ClassMethod|Function_|Closure|ArrowFunction $node): void
    {
        foreach ($node->params as $param) {
            if ($param->type === null) {
                continue;
            }

            if ($this->isBoolType($param->type)) {
                $paramName = $param->var instanceof Variable && \is_string($param->var->name)
                    ? $param->var->name
                    : '?';
                $this->addLocation('boolean_argument', $param, $paramName);
            }
        }
    }

    /**
     * Check if a type node contains bool.
     *
     * Handles:
     * - Simple `bool` (Identifier)
     * - Nullable `?bool` (NullableType wrapping Identifier)
     * - Union types containing `bool` (UnionType with bool Identifier)
     */
    private function isBoolType(Node $type): bool
    {
        // Simple 'bool' type
        if ($type instanceof Node\Identifier && $type->toLowerString() === 'bool') {
            return true;
        }

        // Nullable '?bool' type
        if ($type instanceof Node\NullableType
            && $type->type instanceof Node\Identifier
            && $type->type->toLowerString() === 'bool'
        ) {
            return true;
        }

        // Union type containing 'bool' (e.g., bool|null, bool|string)
        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $unionMember) {
                if ($unionMember instanceof Node\Identifier && $unionMember->toLowerString() === 'bool') {
                    return true;
                }
            }
        }

        return false;
    }
}
