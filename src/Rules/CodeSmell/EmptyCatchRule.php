<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

use Qualimetrix\Core\Violation\Severity;

/**
 * Detects empty catch blocks.
 *
 * Empty catch blocks silently swallow exceptions, hiding potential errors.
 * At minimum, exceptions should be logged.
 */
final class EmptyCatchRule extends AbstractCodeSmellRule
{
    public const string NAME = 'code-smell.empty-catch';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects empty catch blocks';
    }

    protected function getSmellType(): string
    {
        return 'empty_catch';
    }

    protected function getSeverity(): Severity
    {
        return Severity::Error;
    }

    protected function getMessageTemplate(): string
    {
        return 'Empty catch block detected - exceptions should not be silently ignored';
    }

    protected function getRecommendation(): string
    {
        return 'Log the exception or add a comment explaining why it is safe to ignore.';
    }

    /**
     * @return class-string<CodeSmellOptions>
     */
    public static function getOptionsClass(): string
    {
        return CodeSmellOptions::class;
    }
}
