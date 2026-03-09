<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\CodeSmell;

use AiMessDetector\Metrics\ResettableVisitorInterface;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
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
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Goto_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeVisitorAbstract;

/**
 * Visitor for detecting code smells in AST.
 *
 * Detects:
 * - goto statements
 * - eval() expressions
 * - exit()/die() expressions
 * - Empty catch blocks
 * - Debug code (var_dump, print_r, dd, dump, debug_backtrace)
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
        'debug_backtrace',
        'debug_print_backtrace',
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

    public function reset(): void
    {
        $this->locations = [];
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Goto_) {
            $this->addLocation('goto', $node);

            return null;
        }

        if ($node instanceof Eval_) {
            $this->addLocation('eval', $node);

            return null;
        }

        if ($node instanceof Exit_) {
            $this->addLocation('exit', $node);

            return null;
        }

        if ($node instanceof Catch_) {
            $this->checkEmptyCatch($node);

            return null;
        }

        if ($node instanceof FuncCall) {
            $this->checkDebugFunction($node);

            return null;
        }

        if ($node instanceof ErrorSuppress) {
            $this->addLocation('error_suppression', $node);

            return null;
        }

        if ($node instanceof For_ || $node instanceof While_ || $node instanceof Do_) {
            $this->checkCountInLoop($node);

            return null;
        }

        if ($node instanceof Variable) {
            $this->checkSuperglobal($node);

            return null;
        }

        if ($node instanceof ClassMethod || $node instanceof Function_ || $node instanceof Closure || $node instanceof ArrowFunction) {
            $this->checkBooleanArgument($node);

            return null;
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

    private function checkEmptyCatch(Catch_ $node): void
    {
        // Empty catch block = no statements (Nop nodes are comment-only placeholders)
        $realStmts = array_filter($node->stmts, static fn(Node $s): bool => !$s instanceof Node\Stmt\Nop);

        if ($realStmts === []) {
            $this->addLocation('empty_catch', $node);
        }
    }

    private function checkDebugFunction(FuncCall $node): void
    {
        if (!$node->name instanceof Name) {
            return;
        }

        $functionName = $node->name->toLowerString();

        if (\in_array($functionName, self::DEBUG_FUNCTIONS, true)) {
            $this->addLocation('debug_code', $node, $functionName);
        }
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
