<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Security;

/**
 * Detects potential SQL injection vulnerabilities.
 *
 * Checks for superglobals used in SQL query construction via concatenation,
 * interpolation, or direct use in SQL function arguments.
 */
final class SqlInjectionRule extends AbstractSecurityPatternRule
{
    public const string NAME = 'security.sql-injection';
    public const string DOCS_PAGE = 'rules/security.md';
    public const int REMEDIATION_MINUTES = 60;

    protected const string DESCRIPTION = 'Detects potential SQL injection vulnerabilities';
    protected const string PATTERN_TYPE = 'sql_injection';
    protected const string MESSAGE_TEMPLATE = 'Potential SQL injection — use parameterized queries instead of direct superglobal interpolation';
    protected const ?string RECOMMENDATION = 'Use parameterized queries or prepared statements.';
}
