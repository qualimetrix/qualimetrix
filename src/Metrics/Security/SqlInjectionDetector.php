<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Security;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;

/**
 * Detects SQL injection patterns: superglobals used in SQL contexts.
 *
 * Detection vectors:
 * - Concatenation of SQL keywords with superglobals
 * - String interpolation with SQL keywords and superglobals
 * - Direct superglobal usage in SQL function arguments (mysql_query, etc.)
 * - sprintf() with SQL format string and superglobal arguments
 */
final readonly class SqlInjectionDetector
{
    /** @var list<string> SQL-related functions */
    private const SQL_FUNCTIONS = [
        'mysql_query',
        'mysqli_query',
        'pg_query',
        'pg_query_params',
        'sqlite_query',
    ];

    public function __construct(
        private SuperglobalAnalyzer $superglobalAnalyzer,
    ) {}

    /**
     * Detect SQL injection in a function call node.
     *
     * @return list<SecurityPatternLocation>
     */
    public function detectInFuncCall(FuncCall $node): array
    {
        if (!$node->name instanceof Name) {
            return [];
        }

        $functionName = $node->name->toLowerString();

        // Check direct SQL functions
        if (\in_array($functionName, self::SQL_FUNCTIONS, true)) {
            foreach ($node->getArgs() as $arg) {
                if ($this->superglobalAnalyzer->containsSuperglobal($arg->value)) {
                    $varName = $this->superglobalAnalyzer->findSuperglobalName($arg->value);

                    return [
                        new SecurityPatternLocation(
                            type: 'sql_injection',
                            line: $node->getStartLine(),
                            context: "\${$varName} in {$functionName}() call",
                        ),
                    ];
                }
            }
        }

        // Check sprintf with SQL keywords and superglobals
        if ($functionName === 'sprintf') {
            return $this->detectInSprintf($node);
        }

        return [];
    }

    /**
     * Detect SQL injection in a concatenation node.
     *
     * @return list<SecurityPatternLocation>
     */
    public function detectInConcat(Concat $node): array
    {
        $parts = $this->superglobalAnalyzer->flattenConcat($node);

        $hasSqlKeyword = false;
        $superglobalName = null;

        foreach ($parts as $part) {
            if ($part instanceof Node\Scalar\String_ && $this->containsSqlKeyword($part->value)) {
                $hasSqlKeyword = true;
            }

            if ($superglobalName === null && $this->superglobalAnalyzer->containsSuperglobal($part)) {
                $superglobalName = $this->superglobalAnalyzer->findSuperglobalName($part);
            }
        }

        if ($hasSqlKeyword && $superglobalName !== null) {
            return [
                new SecurityPatternLocation(
                    type: 'sql_injection',
                    line: $node->getStartLine(),
                    context: "\${$superglobalName} concatenated with SQL query",
                ),
            ];
        }

        return [];
    }

    /**
     * Detect SQL injection in an interpolated string node.
     *
     * @return list<SecurityPatternLocation>
     */
    public function detectInInterpolation(InterpolatedString $node): array
    {
        $hasSqlKeyword = false;
        $superglobalName = null;

        foreach ($node->parts as $part) {
            if ($part instanceof Node\InterpolatedStringPart && $this->containsSqlKeyword($part->value)) {
                $hasSqlKeyword = true;
            }

            if ($superglobalName === null && $part instanceof Expr && $this->superglobalAnalyzer->containsSuperglobal($part)) {
                $superglobalName = $this->superglobalAnalyzer->findSuperglobalName($part);
            }
        }

        if ($hasSqlKeyword && $superglobalName !== null) {
            return [
                new SecurityPatternLocation(
                    type: 'sql_injection',
                    line: $node->getStartLine(),
                    context: "\${$superglobalName} interpolated in SQL query",
                ),
            ];
        }

        return [];
    }

    /**
     * Check if a FuncCall is a SQL-related function.
     */
    public function isSqlFuncCall(FuncCall $node): bool
    {
        if (!$node->name instanceof Name) {
            return false;
        }

        return \in_array($node->name->toLowerString(), self::SQL_FUNCTIONS, true);
    }

    /**
     * Check sprintf with SQL keywords and superglobals.
     *
     * @return list<SecurityPatternLocation>
     */
    private function detectInSprintf(FuncCall $node): array
    {
        $args = $node->getArgs();
        if ($args === []) {
            return [];
        }

        $firstArg = $args[0]->value;
        if (!$firstArg instanceof Node\Scalar\String_) {
            return [];
        }

        if (!$this->containsSqlKeyword($firstArg->value)) {
            return [];
        }

        // Check remaining arguments for superglobals
        for ($i = 1, $count = \count($args); $i < $count; $i++) {
            if ($this->superglobalAnalyzer->containsSuperglobal($args[$i]->value)) {
                $varName = $this->superglobalAnalyzer->findSuperglobalName($args[$i]->value);

                return [
                    new SecurityPatternLocation(
                        type: 'sql_injection',
                        line: $node->getStartLine(),
                        context: "\${$varName} in sprintf() with SQL query",
                    ),
                ];
            }
        }

        return [];
    }

    /**
     * Check if a string contains SQL keywords (matched as whole words only).
     */
    private function containsSqlKeyword(string $value): bool
    {
        return (bool) preg_match(
            '/\b(?:SELECT|INSERT|UPDATE|DELETE|WHERE|FROM|INTO|SET|VALUES)\b/i',
            $value,
        );
    }
}
