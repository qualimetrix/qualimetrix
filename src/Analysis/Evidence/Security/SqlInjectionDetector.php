<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Security;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
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
 *
 * A superglobal search looks through concatenation and interpolation, so a
 * call or a concatenation already reaches the reads an interpolated or
 * concatenated query nested in it holds. The caller passes the reads an
 * enclosing query already reported, and a nested query is reported only for a
 * read no enclosing query reached, see {@see SecurityPatternVisitor}.
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
     * Detect SQL injection in any node that can build or run a query.
     *
     * @param array<int, true> $reported object ids of the reads an enclosing query already reported
     *
     * @return list<SecurityPatternLocation>
     */
    public function detect(Node $node, array $reported = []): array
    {
        $query = $this->query($node);
        if ($query === null) {
            return [];
        }

        foreach ($this->superglobalAnalyzer->readsInParts($query[0]) as $read) {
            if (!isset($reported[spl_object_id($read)]) && \is_string($read->name)) {
                return [
                    new SecurityPatternLocation(
                        type: 'sql_injection',
                        line: $node->getStartLine(),
                        context: "\${$read->name} {$query[1]}",
                    ),
                ];
            }
        }

        return [];
    }

    /**
     * Every superglobal read the query built or run by $node holds; empty when
     * $node is not a query.
     *
     * @return list<Variable>
     */
    public function reads(Node $node): array
    {
        $query = $this->query($node);

        return $query === null ? [] : $this->superglobalAnalyzer->readsInParts($query[0]);
    }

    /**
     * The operands that carry user input into the query $node builds or runs,
     * and how the finding describes the way they get there; null when $node
     * is not a query: a direct SQL function call, `sprintf()` with an SQL
     * format literal, or a concatenation or interpolation with an SQL keyword.
     *
     * @return array{array<Expr|Node\InterpolatedStringPart>, string}|null
     */
    private function query(Node $node): ?array
    {
        return match (true) {
            $node instanceof FuncCall => $this->callQuery($node),
            $node instanceof Concat => $this->textQuery($this->superglobalAnalyzer->flattenConcat($node), 'concatenated with SQL query'),
            $node instanceof InterpolatedString => $this->textQuery($node->parts, 'interpolated in SQL query'),
            default => null,
        };
    }

    /**
     * @return array{array<Expr>, string}|null
     */
    private function callQuery(FuncCall $node): ?array
    {
        if (!$node->name instanceof Name || $node->isFirstClassCallable()) {
            return null;
        }

        $functionName = $node->name->toLowerString();
        $values = array_map(static fn(Node\Arg $arg): Expr => $arg->value, $node->getArgs());

        if (\in_array($functionName, self::SQL_FUNCTIONS, true)) {
            return [$values, "in {$functionName}() call"];
        }

        $format = $values[0] ?? null;

        return $functionName === 'sprintf' && $format instanceof Node\Scalar\String_ && $this->containsSqlKeyword($format->value)
            ? [\array_slice($values, 1), 'in sprintf() with SQL query']
            : null;
    }

    /**
     * @param array<Expr|Node\InterpolatedStringPart> $parts
     *
     * @return array{array<Expr|Node\InterpolatedStringPart>, string}|null
     */
    private function textQuery(array $parts, string $how): ?array
    {
        foreach ($parts as $part) {
            if (($part instanceof Node\Scalar\String_ || $part instanceof Node\InterpolatedStringPart) && $this->containsSqlKeyword($part->value)) {
                return [$parts, $how];
            }
        }

        return null;
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
