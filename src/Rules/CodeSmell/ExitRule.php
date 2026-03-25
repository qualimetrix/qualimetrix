<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

use Qualimetrix\Core\Violation\Severity;

/**
 * Detects usage of exit() and die().
 *
 * exit()/die() should be avoided in library/application code
 * as they terminate the entire script. Use exceptions instead.
 */
final class ExitRule extends AbstractCodeSmellRule
{
    public const string NAME = 'code-smell.exit';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects usage of exit() and die()';
    }

    protected function getSmellType(): string
    {
        return 'exit';
    }

    protected function getSeverity(): Severity
    {
        return Severity::Warning;
    }

    protected function getMessageTemplate(): string
    {
        return 'exit()/die() usage detected - use exceptions instead';
    }

    protected function getRecommendation(): string
    {
        return 'Throw an exception instead of exit/die to allow proper error handling.';
    }

    /**
     * @return class-string<CodeSmellOptions>
     */
    public static function getOptionsClass(): string
    {
        return CodeSmellOptions::class;
    }
}
