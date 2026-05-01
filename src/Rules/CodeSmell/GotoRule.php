<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

use Qualimetrix\Core\Violation\Severity;

/**
 * Detects usage of goto statement.
 *
 * The goto statement should be avoided as it makes code flow hard to follow
 * and can lead to spaghetti code.
 */
final class GotoRule extends AbstractCodeSmellRule
{
    public const string NAME = 'code-smell.goto';
    protected const string DESCRIPTION = 'Detects usage of goto statement';
    protected const string SMELL_TYPE = 'goto';
    protected const Severity SEVERITY = Severity::Error;
    protected const string MESSAGE_TEMPLATE = 'goto statement detected - avoid using goto';
    protected const ?string RECOMMENDATION = 'Replace goto with structured control flow (loops, early returns).';
}
