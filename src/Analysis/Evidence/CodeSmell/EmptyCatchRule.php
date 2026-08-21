<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Detects empty catch blocks.
 *
 * Empty catch blocks silently swallow exceptions, hiding potential errors.
 * At minimum, exceptions should be logged.
 */
final class EmptyCatchRule extends AbstractCodeSmellRule
{
    public const string NAME = 'code-smell.empty-catch';
    public const string DOCS_PAGE = 'rules/code-smell.md';
    protected const string DESCRIPTION = 'Detects empty catch blocks';
    protected const string SMELL_TYPE = 'empty_catch';
    protected const Severity SEVERITY = Severity::Error;
    protected const string MESSAGE_TEMPLATE = 'Empty catch block detected - exceptions should not be silently ignored';
    protected const ?string RECOMMENDATION = 'Log the exception or add a comment explaining why it is safe to ignore.';
}
