<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

/**
 * Detects direct access to superglobals ($_GET, $_POST, etc).
 *
 * Direct superglobal access violates dependency injection principles
 * and makes code harder to test. Use Request objects instead.
 */
final class SuperglobalsRule extends AbstractCodeSmellRule
{
    public const string NAME = 'code-smell.superglobals';
    protected const string DESCRIPTION = 'Detects direct access to superglobals';
    protected const string SMELL_TYPE = 'superglobals';
    protected const string MESSAGE_TEMPLATE = 'Direct superglobal access detected - use dependency injection';
    protected const ?string RECOMMENDATION = 'Use dependency injection or a request object instead of direct superglobal access.';
}
