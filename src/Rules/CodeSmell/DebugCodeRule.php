<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\CodeSmell;

use AiMessDetector\Core\Violation\Severity;

/**
 * Detects debug code (var_dump, print_r, dd, etc).
 *
 * Debug functions should not be present in production code.
 */
final class DebugCodeRule extends AbstractCodeSmellRule
{
    public const string NAME = 'code-smell.debug-code';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects debug code (var_dump, print_r, dd, etc)';
    }

    protected function getSmellType(): string
    {
        return 'debug_code';
    }

    protected function getSeverity(): Severity
    {
        return Severity::Warning;
    }

    protected function getMessageTemplate(): string
    {
        return 'Debug function call detected - remove before production';
    }

    protected function getRecommendation(): string
    {
        return 'Remove debug statements before merging to production.';
    }

    /**
     * @return class-string<CodeSmellOptions>
     */
    public static function getOptionsClass(): string
    {
        return CodeSmellOptions::class;
    }
}
