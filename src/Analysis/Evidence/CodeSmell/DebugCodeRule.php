<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Detects debug code (var_dump, print_r, dd, etc).
 *
 * Debug functions should not be present in production code.
 */
final class DebugCodeRule extends AbstractCodeSmellRule
{
    public const string NAME = 'code-smell.debug-code';
    public const string DOCS_PAGE = 'rules/code-smell.md';
    public const int REMEDIATION_MINUTES = 5;
    protected const string DESCRIPTION = 'Detects debug code (var_dump, print_r, dd, etc)';
    protected const string SMELL_TYPE = 'debug_code';
    protected const Severity SEVERITY = Severity::Error;
    protected const string MESSAGE_TEMPLATE = 'Debug function call detected - remove before production';
    protected const ?string RECOMMENDATION = 'Remove debug statements before merging to production.';
}
