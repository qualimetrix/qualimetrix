<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\CodeSmell;

use AiMessDetector\Core\Violation\Severity;

/**
 * Detects usage of goto statement.
 *
 * The goto statement should be avoided as it makes code flow hard to follow
 * and can lead to spaghetti code.
 */
final class GotoRule extends AbstractCodeSmellRule
{
    public const string NAME = 'code-smell.goto';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects usage of goto statement';
    }

    protected function getSmellType(): string
    {
        return 'goto';
    }

    protected function getSeverity(): Severity
    {
        return Severity::Error;
    }

    protected function getMessageTemplate(): string
    {
        return 'goto statement detected - avoid using goto';
    }

    /**
     * @return class-string<CodeSmellOptions>
     */
    public static function getOptionsClass(): string
    {
        return CodeSmellOptions::class;
    }
}
