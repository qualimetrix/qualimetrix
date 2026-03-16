<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Security;

use AiMessDetector\Metrics\ResettableVisitorInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Print_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\NodeVisitorAbstract;

/**
 * AST visitor that detects security patterns: SQL injection, XSS, command injection.
 *
 * Detects superglobals ($_GET, $_POST, $_REQUEST, $_COOKIE) used in dangerous contexts:
 * - SQL injection: concatenation/interpolation with SQL keywords, or in SQL function args
 * - XSS: echo/print of superglobals without sanitization
 * - Command injection: superglobals in exec/system/passthru/shell_exec/proc_open/popen args
 */
final class SecurityPatternVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    /** @var list<string> Superglobals considered dangerous for user input */
    private const DANGEROUS_SUPERGLOBALS = [
        '_GET',
        '_POST',
        '_REQUEST',
        '_COOKIE',
    ];

    /** @var list<string> SQL-related functions */
    private const SQL_FUNCTIONS = [
        'mysql_query',
        'mysqli_query',
        'pg_query',
        'pg_query_params',
        'sqlite_query',
    ];

    /** @var list<string> Command execution functions */
    private const COMMAND_FUNCTIONS = [
        'exec',
        'system',
        'passthru',
        'shell_exec',
        'proc_open',
        'popen',
    ];

    /** @var list<string> XSS sanitization functions */
    private const XSS_SANITIZERS = [
        'htmlspecialchars',
        'htmlentities',
        'strip_tags',
        'intval',
    ];

    /** @var list<string> Command injection sanitization functions */
    private const COMMAND_SANITIZERS = [
        'escapeshellarg',
        'escapeshellcmd',
    ];

    /** @var list<SecurityPatternLocation> */
    private array $locations = [];

    /** @var int Depth of Concat nesting (to only process topmost Concat) */
    private int $concatDepth = 0;

    /** @var int Depth of SQL function call nesting (to avoid duplicate detection) */
    private int $sqlFuncCallDepth = 0;

    public function reset(): void
    {
        $this->locations = [];
        $this->concatDepth = 0;
        $this->sqlFuncCallDepth = 0;
    }

    public function enterNode(Node $node): ?int
    {
        // echo statement: check for XSS
        if ($node instanceof Node\Stmt\Echo_) {
            $this->checkEchoXss($node);

            return null;
        }

        // print expression: check for XSS
        if ($node instanceof Print_) {
            $this->checkPrintXss($node);

            return null;
        }

        // Function calls: check for SQL injection and command injection
        if ($node instanceof FuncCall) {
            if ($this->isSqlFuncCall($node)) {
                $this->sqlFuncCallDepth++;
            }
            $this->checkSqlInjectionFuncCall($node);
            $this->checkCommandInjection($node);

            return null;
        }

        // Concatenation: check for SQL injection (only at topmost Concat node)
        if ($node instanceof Concat) {
            $this->concatDepth++;
            if ($this->concatDepth === 1 && $this->sqlFuncCallDepth === 0) {
                $this->checkSqlInjectionConcat($node);
            }

            return null;
        }

        // String interpolation: check for SQL injection
        if ($node instanceof InterpolatedString) {
            $this->checkSqlInjectionInterpolation($node);

            return null;
        }

        return null;
    }

    /**
     * @return null
     */
    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Concat) {
            $this->concatDepth--;
        }

        if ($node instanceof FuncCall && $this->isSqlFuncCall($node)) {
            $this->sqlFuncCallDepth--;
        }

        return null;
    }

    /**
     * @return list<SecurityPatternLocation>
     */
    public function getLocations(): array
    {
        return $this->locations;
    }

    /**
     * @return list<SecurityPatternLocation>
     */
    public function getLocationsByType(string $type): array
    {
        return array_values(
            array_filter(
                $this->locations,
                static fn(SecurityPatternLocation $loc): bool => $loc->type === $type,
            ),
        );
    }

    /**
     * Check if a FuncCall is a SQL-related function.
     */
    private function isSqlFuncCall(FuncCall $node): bool
    {
        if (!$node->name instanceof Name) {
            return false;
        }

        return \in_array($node->name->toLowerString(), self::SQL_FUNCTIONS, true);
    }

    /**
     * Check echo statement for XSS (unsanitized superglobal output).
     */
    private function checkEchoXss(Node\Stmt\Echo_ $node): void
    {
        foreach ($node->exprs as $expr) {
            if ($this->isUnsanitizedSuperglobal($expr, self::XSS_SANITIZERS)) {
                $varName = $this->getSuperglobalName($expr);
                $this->locations[] = new SecurityPatternLocation(
                    type: 'xss',
                    line: $node->getStartLine(),
                    context: "echo \${$varName} without sanitization",
                );
            } elseif ($expr instanceof InterpolatedString) {
                $varName = $this->findSuperglobalInInterpolatedString($expr);
                if ($varName !== null) {
                    $this->locations[] = new SecurityPatternLocation(
                        type: 'xss',
                        line: $node->getStartLine(),
                        context: "echo \${$varName} without sanitization",
                    );
                }
            } elseif ($this->containsUnsanitizedSuperglobalInExpr($expr, self::XSS_SANITIZERS)) {
                $varName = $this->findUnsanitizedSuperglobalName($expr, self::XSS_SANITIZERS);
                if ($varName !== null) {
                    $this->locations[] = new SecurityPatternLocation(
                        type: 'xss',
                        line: $node->getStartLine(),
                        context: "echo \${$varName} without sanitization",
                    );
                }
            }
        }
    }

    /**
     * Check print expression for XSS (unsanitized superglobal output).
     */
    private function checkPrintXss(Print_ $node): void
    {
        if ($this->isUnsanitizedSuperglobal($node->expr, self::XSS_SANITIZERS)) {
            $varName = $this->getSuperglobalName($node->expr);
            $this->locations[] = new SecurityPatternLocation(
                type: 'xss',
                line: $node->getStartLine(),
                context: "print \${$varName} without sanitization",
            );
        } elseif ($node->expr instanceof InterpolatedString) {
            $varName = $this->findSuperglobalInInterpolatedString($node->expr);
            if ($varName !== null) {
                $this->locations[] = new SecurityPatternLocation(
                    type: 'xss',
                    line: $node->getStartLine(),
                    context: "print \${$varName} without sanitization",
                );
            }
        } elseif ($this->containsUnsanitizedSuperglobalInExpr($node->expr, self::XSS_SANITIZERS)) {
            $varName = $this->findUnsanitizedSuperglobalName($node->expr, self::XSS_SANITIZERS);
            if ($varName !== null) {
                $this->locations[] = new SecurityPatternLocation(
                    type: 'xss',
                    line: $node->getStartLine(),
                    context: "print \${$varName} without sanitization",
                );
            }
        }
    }

    /**
     * Check function calls for SQL injection (superglobal in SQL function args).
     */
    private function checkSqlInjectionFuncCall(FuncCall $node): void
    {
        if (!$node->name instanceof Name) {
            return;
        }

        $functionName = $node->name->toLowerString();

        // Check direct SQL functions
        if (\in_array($functionName, self::SQL_FUNCTIONS, true)) {
            foreach ($node->getArgs() as $arg) {
                if ($this->containsSuperglobal($arg->value)) {
                    $varName = $this->findSuperglobalName($arg->value);
                    $this->locations[] = new SecurityPatternLocation(
                        type: 'sql_injection',
                        line: $node->getStartLine(),
                        context: "\${$varName} in {$functionName}() call",
                    );

                    return;
                }
            }
        }

        // Check sprintf with SQL keywords and superglobals
        if ($functionName === 'sprintf') {
            $args = $node->getArgs();
            if ($args === []) {
                return;
            }

            $firstArg = $args[0]->value;
            if (!$firstArg instanceof Node\Scalar\String_) {
                return;
            }

            if (!$this->containsSqlKeyword($firstArg->value)) {
                return;
            }

            // Check remaining arguments for superglobals
            for ($i = 1, $count = \count($args); $i < $count; $i++) {
                if ($this->containsSuperglobal($args[$i]->value)) {
                    $varName = $this->findSuperglobalName($args[$i]->value);
                    $this->locations[] = new SecurityPatternLocation(
                        type: 'sql_injection',
                        line: $node->getStartLine(),
                        context: "\${$varName} in sprintf() with SQL query",
                    );

                    return;
                }
            }
        }
    }

    /**
     * Check concatenation for SQL injection (SQL keyword + superglobal).
     */
    private function checkSqlInjectionConcat(Concat $node): void
    {
        // Collect all parts of the concatenation chain
        $parts = $this->flattenConcat($node);

        $hasSqlKeyword = false;
        $superglobalName = null;

        foreach ($parts as $part) {
            if ($part instanceof Node\Scalar\String_ && $this->containsSqlKeyword($part->value)) {
                $hasSqlKeyword = true;
            }

            if ($superglobalName === null && $this->containsSuperglobal($part)) {
                $superglobalName = $this->findSuperglobalName($part);
            }
        }

        if ($hasSqlKeyword && $superglobalName !== null) {
            $this->locations[] = new SecurityPatternLocation(
                type: 'sql_injection',
                line: $node->getStartLine(),
                context: "\${$superglobalName} concatenated with SQL query",
            );
        }
    }

    /**
     * Check string interpolation for SQL injection.
     */
    private function checkSqlInjectionInterpolation(InterpolatedString $node): void
    {
        $hasSqlKeyword = false;
        $superglobalName = null;

        foreach ($node->parts as $part) {
            if ($part instanceof Node\InterpolatedStringPart && $this->containsSqlKeyword($part->value)) {
                $hasSqlKeyword = true;
            }

            if ($superglobalName === null && $part instanceof Expr && $this->containsSuperglobal($part)) {
                $superglobalName = $this->findSuperglobalName($part);
            }
        }

        if ($hasSqlKeyword && $superglobalName !== null) {
            $this->locations[] = new SecurityPatternLocation(
                type: 'sql_injection',
                line: $node->getStartLine(),
                context: "\${$superglobalName} interpolated in SQL query",
            );
        }
    }

    /**
     * Check function call for command injection (superglobal in command function args).
     */
    private function checkCommandInjection(FuncCall $node): void
    {
        if (!$node->name instanceof Name) {
            return;
        }

        $functionName = $node->name->toLowerString();

        if (!\in_array($functionName, self::COMMAND_FUNCTIONS, true)) {
            return;
        }

        foreach ($node->getArgs() as $arg) {
            if ($this->isUnsanitizedSuperglobal($arg->value, self::COMMAND_SANITIZERS)) {
                $varName = $this->getSuperglobalName($arg->value);
                $this->locations[] = new SecurityPatternLocation(
                    type: 'command_injection',
                    line: $node->getStartLine(),
                    context: "\${$varName} in {$functionName}() call",
                );

                return;
            }

            // Check interpolated strings for unsanitized superglobals
            if ($arg->value instanceof InterpolatedString) {
                $varName = $this->findSuperglobalInInterpolatedString($arg->value);
                if ($varName !== null) {
                    $this->locations[] = new SecurityPatternLocation(
                        type: 'command_injection',
                        line: $node->getStartLine(),
                        context: "\${$varName} in {$functionName}() call",
                    );

                    return;
                }
            }

            // Also check concatenation containing unsanitized superglobal
            if ($this->containsUnsanitizedSuperglobalInExpr($arg->value, self::COMMAND_SANITIZERS)) {
                $varName = $this->findUnsanitizedSuperglobalName($arg->value, self::COMMAND_SANITIZERS);
                if ($varName !== null) {
                    $this->locations[] = new SecurityPatternLocation(
                        type: 'command_injection',
                        line: $node->getStartLine(),
                        context: "\${$varName} in {$functionName}() call",
                    );

                    return;
                }
            }
        }
    }

    /**
     * Check if an expression is an unsanitized superglobal (direct access or array dim fetch).
     *
     * @param list<string> $sanitizers
     */
    private function isUnsanitizedSuperglobal(Expr $expr, array $sanitizers): bool
    {
        // Check for sanitization wrapper: htmlspecialchars($_GET['x']), etc.
        if ($expr instanceof FuncCall && $expr->name instanceof Name) {
            $funcName = $expr->name->toLowerString();
            if (\in_array($funcName, $sanitizers, true)) {
                return false;
            }
        }

        // Check for int/float cast: (int)$_GET['x']
        if ($expr instanceof Cast\Int_ || $expr instanceof Cast\Double) {
            return false;
        }

        // Check for intval wrapper
        if ($expr instanceof FuncCall && $expr->name instanceof Name && $expr->name->toLowerString() === 'intval') {
            return false;
        }

        return $this->isDangerousSuperglobal($expr);
    }

    /**
     * Check if an expression is a dangerous superglobal variable or array access.
     */
    private function isDangerousSuperglobal(Expr $expr): bool
    {
        // Direct: $_GET, $_POST, etc.
        if ($expr instanceof Variable && \is_string($expr->name)) {
            return \in_array($expr->name, self::DANGEROUS_SUPERGLOBALS, true);
        }

        // Array access: $_GET['key']
        if ($expr instanceof ArrayDimFetch) {
            return $this->isDangerousSuperglobal($expr->var);
        }

        return false;
    }

    /**
     * Check if an expression tree contains a superglobal (for SQL injection checks).
     */
    private function containsSuperglobal(Expr $expr): bool
    {
        if ($this->isDangerousSuperglobal($expr)) {
            return true;
        }

        if ($expr instanceof Concat) {
            return $this->containsSuperglobal($expr->left) || $this->containsSuperglobal($expr->right);
        }

        if ($expr instanceof ArrayDimFetch) {
            return $this->isDangerousSuperglobal($expr->var);
        }

        return false;
    }

    /**
     * Check if an expression tree contains an unsanitized superglobal.
     *
     * @param list<string> $sanitizers
     */
    private function containsUnsanitizedSuperglobalInExpr(Expr $expr, array $sanitizers): bool
    {
        if ($this->isUnsanitizedSuperglobal($expr, $sanitizers)) {
            return true;
        }

        if ($expr instanceof Concat) {
            return $this->containsUnsanitizedSuperglobalInExpr($expr->left, $sanitizers)
                || $this->containsUnsanitizedSuperglobalInExpr($expr->right, $sanitizers);
        }

        return false;
    }

    /**
     * Find the name of an unsanitized superglobal in expression tree.
     *
     * @param list<string> $sanitizers
     */
    private function findUnsanitizedSuperglobalName(Expr $expr, array $sanitizers): ?string
    {
        if ($this->isUnsanitizedSuperglobal($expr, $sanitizers)) {
            return $this->getSuperglobalName($expr);
        }

        if ($expr instanceof Concat) {
            return $this->findUnsanitizedSuperglobalName($expr->left, $sanitizers)
                ?? $this->findUnsanitizedSuperglobalName($expr->right, $sanitizers);
        }

        return null;
    }

    /**
     * Get the superglobal variable name from an expression.
     */
    private function getSuperglobalName(Expr $expr): string
    {
        if ($expr instanceof Variable && \is_string($expr->name)) {
            return $expr->name;
        }

        if ($expr instanceof ArrayDimFetch) {
            return $this->getSuperglobalName($expr->var);
        }

        return 'unknown';
    }

    /**
     * Find a superglobal name in an expression tree.
     */
    private function findSuperglobalName(Expr $expr): string
    {
        if ($this->isDangerousSuperglobal($expr)) {
            return $this->getSuperglobalName($expr);
        }

        if ($expr instanceof Concat) {
            if ($this->containsSuperglobal($expr->left)) {
                return $this->findSuperglobalName($expr->left);
            }

            return $this->findSuperglobalName($expr->right);
        }

        if ($expr instanceof ArrayDimFetch) {
            return $this->getSuperglobalName($expr);
        }

        return 'unknown';
    }

    /**
     * Check if a string contains SQL keywords (matched as whole words only).
     *
     * Uses word-boundary regex to avoid false positives on common English words
     * like "SET", "FROM", "INTO" appearing in non-SQL contexts.
     */
    private function containsSqlKeyword(string $value): bool
    {
        return (bool) preg_match(
            '/\b(?:SELECT|INSERT|UPDATE|DELETE|WHERE|FROM|INTO|SET|VALUES)\b/i',
            $value,
        );
    }

    /**
     * Find an unsanitized superglobal in an InterpolatedString node.
     *
     * InterpolatedString parts are either InterpolatedStringPart (literal text)
     * or Expr nodes (interpolated expressions like {$_GET['name']}).
     */
    private function findSuperglobalInInterpolatedString(InterpolatedString $node): ?string
    {
        foreach ($node->parts as $part) {
            if ($part instanceof Expr && $this->isDangerousSuperglobal($part)) {
                return $this->getSuperglobalName($part);
            }
        }

        return null;
    }

    /**
     * Flatten a concatenation chain into individual parts.
     *
     * @return list<Expr>
     */
    private function flattenConcat(Concat $node): array
    {
        $parts = [];

        if ($node->left instanceof Concat) {
            $parts = [...$parts, ...$this->flattenConcat($node->left)];
        } else {
            $parts[] = $node->left;
        }

        if ($node->right instanceof Concat) {
            $parts = [...$parts, ...$this->flattenConcat($node->right)];
        } else {
            $parts[] = $node->right;
        }

        return $parts;
    }
}
