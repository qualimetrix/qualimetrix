<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Detects usage of eval() function.
 *
 * The eval() function is a security risk and should be avoided.
 * It executes arbitrary PHP code which can lead to code injection vulnerabilities.
 */
final class EvalRule extends AbstractCodeSmellRule
{
    public const string NAME = 'code-smell.eval';
    public const string DOCS_PAGE = 'rules/code-smell.md';
    protected const string DESCRIPTION = 'Detects usage of eval() function';
    protected const string SMELL_TYPE = 'eval';
    protected const Severity SEVERITY = Severity::Error;
    protected const string MESSAGE_TEMPLATE = 'eval() usage detected - security risk';
    protected const ?string RECOMMENDATION = 'Replace eval() with a safer alternative (closures, reflection, or template engine).';
}
