<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

use Qualimetrix\Core\Violation\Severity;

/**
 * Detects count() calls in loop conditions.
 *
 * Calling count() in a loop condition recalculates the count on every iteration.
 * Store the count in a variable before the loop for better performance.
 */
final class CountInLoopRule extends AbstractCodeSmellRule
{
    public const string NAME = 'code-smell.count-in-loop';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects count() calls in loop conditions';
    }

    protected function getSmellType(): string
    {
        return 'count_in_loop';
    }

    protected function getSeverity(): Severity
    {
        return Severity::Warning;
    }

    protected function getMessageTemplate(): string
    {
        return 'count() in loop condition detected - store in variable before loop';
    }

    protected function getRecommendation(): string
    {
        return 'Store the count result in a variable before the loop.';
    }

    /**
     * @return class-string<CodeSmellOptions>
     */
    public static function getOptionsClass(): string
    {
        return CodeSmellOptions::class;
    }
}
