<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\CodeSmell;

use AiMessDetector\Core\Violation\Severity;

/**
 * Detects usage of error suppression operator (@).
 *
 * The @ operator hides errors which can make debugging difficult.
 * Handle errors explicitly instead.
 */
final class ErrorSuppressionRule extends AbstractCodeSmellRule
{
    public const string NAME = 'code-smell.error-suppression';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects usage of error suppression operator (@)';
    }

    protected function getSmellType(): string
    {
        return 'error_suppression';
    }

    protected function getSeverity(): Severity
    {
        return Severity::Warning;
    }

    protected function getMessageTemplate(): string
    {
        return 'Error suppression operator (@) detected - handle errors explicitly';
    }

    /**
     * @return class-string<CodeSmellOptions>
     */
    public static function getOptionsClass(): string
    {
        return CodeSmellOptions::class;
    }
}
