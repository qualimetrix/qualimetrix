<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

/**
 * Detects usage of exit() and die().
 *
 * exit()/die() should be avoided in library/application code
 * as they terminate the entire script. Use exceptions instead.
 */
final class ExitRule extends AbstractCodeSmellRule
{
    public const string NAME = 'code-smell.exit';
    protected const string DESCRIPTION = 'Detects usage of exit() and die()';
    protected const string SMELL_TYPE = 'exit';
    protected const string MESSAGE_TEMPLATE = 'exit()/die() usage detected - use exceptions instead';
    protected const ?string RECOMMENDATION = 'Throw an exception instead of exit/die to allow proper error handling.';
}
