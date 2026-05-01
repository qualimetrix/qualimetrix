<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

/**
 * Detects debug code (var_dump, print_r, dd, etc).
 *
 * Debug functions should not be present in production code.
 */
final class DebugCodeRule extends AbstractCodeSmellRule
{
    public const string NAME = 'code-smell.debug-code';
    protected const string DESCRIPTION = 'Detects debug code (var_dump, print_r, dd, etc)';
    protected const string SMELL_TYPE = 'debug_code';
    protected const string MESSAGE_TEMPLATE = 'Debug function call detected - remove before production';
    protected const ?string RECOMMENDATION = 'Remove debug statements before merging to production.';
}
