<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

use Qualimetrix\Core\Violation\Severity;

/**
 * Detects direct access to superglobals ($_GET, $_POST, etc).
 *
 * Direct superglobal access violates dependency injection principles
 * and makes code harder to test. Use Request objects instead.
 */
final class SuperglobalsRule extends AbstractCodeSmellRule
{
    public const string NAME = 'code-smell.superglobals';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects direct access to superglobals';
    }

    protected function getSmellType(): string
    {
        return 'superglobals';
    }

    protected function getSeverity(): Severity
    {
        return Severity::Warning;
    }

    protected function getMessageTemplate(): string
    {
        return 'Direct superglobal access detected - use dependency injection';
    }

    protected function getRecommendation(): string
    {
        return 'Use dependency injection or a request object instead of direct superglobal access.';
    }

    /**
     * @return class-string<CodeSmellOptions>
     */
    public static function getOptionsClass(): string
    {
        return CodeSmellOptions::class;
    }
}
