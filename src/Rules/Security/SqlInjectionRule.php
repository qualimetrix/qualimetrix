<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Security;

use Qualimetrix\Core\Violation\Severity;

/**
 * Detects potential SQL injection vulnerabilities.
 *
 * Checks for superglobals used in SQL query construction via concatenation,
 * interpolation, or direct use in SQL function arguments.
 */
final class SqlInjectionRule extends AbstractSecurityPatternRule
{
    public const string NAME = 'security.sql-injection';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects potential SQL injection vulnerabilities';
    }

    protected function getPatternType(): string
    {
        return 'sql_injection';
    }

    protected function getSeverity(): Severity
    {
        return Severity::Error;
    }

    protected function getMessageTemplate(): string
    {
        return 'Potential SQL injection — use parameterized queries instead of direct superglobal interpolation';
    }

    protected function getRecommendation(): string
    {
        return 'Use parameterized queries or prepared statements.';
    }
}
