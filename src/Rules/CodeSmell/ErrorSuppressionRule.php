<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

use Qualimetrix\Core\Violation\Severity;

/**
 * Detects usage of error suppression operator (@).
 *
 * The @ operator hides errors which can make debugging difficult.
 * Handle errors explicitly instead.
 *
 * Supports `allowed_functions` option to whitelist specific functions
 * where @ usage is acceptable (e.g., fopen, unlink).
 */
final class ErrorSuppressionRule extends AbstractCodeSmellRule
{
    public const string NAME = 'code-smell.error-suppression';
    protected const string DESCRIPTION = 'Detects usage of error suppression operator (@)';
    protected const string SMELL_TYPE = 'error_suppression';
    protected const Severity SEVERITY = Severity::Warning;
    protected const string MESSAGE_TEMPLATE = 'Error suppression operator (@) detected - handle errors explicitly';
    protected const ?string MESSAGE_TEMPLATE_WITH_EXTRA = 'Error suppression (@) on %s() - handle errors explicitly';
    protected const ?string RECOMMENDATION = 'Handle the error explicitly with try/catch or conditional checks.';

    /**
     * @return class-string<ErrorSuppressionOptions>
     */
    public static function getOptionsClass(): string
    {
        return ErrorSuppressionOptions::class;
    }
}
