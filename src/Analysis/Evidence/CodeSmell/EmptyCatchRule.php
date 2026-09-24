<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Detects empty catch blocks.
 *
 * Empty catch blocks silently swallow exceptions, hiding potential errors.
 * At minimum, exceptions should be logged. A catch holding only a comment is still empty.
 * The one exception, a foreach chain of attempts, is decided by ChainOfAttempts.
 */
final class EmptyCatchRule extends AbstractCodeSmellRule
{
    public const string NAME = 'code-smell.empty-catch';
    public const string DOCS_PAGE = 'rules/code-smell.md';
    public const int REMEDIATION_MINUTES = 10;
    protected const string DESCRIPTION = 'Detects empty catch blocks';
    protected const string SMELL_TYPE = 'empty_catch';
    protected const Severity SEVERITY = Severity::Error;
    protected const string MESSAGE_TEMPLATE = 'Empty catch block detected - exceptions should not be silently ignored';
    protected const ?string RECOMMENDATION = 'Log or rethrow the exception, or handle it explicitly. A comment alone does not clear this finding; suppress an intentional ignore with `@qmx-ignore code-smell.empty-catch` and a reason.';
}
