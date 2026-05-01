<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

/**
 * Detects count() calls in loop conditions.
 *
 * Calling count() in a loop condition recalculates the count on every iteration.
 * Store the count in a variable before the loop for better performance.
 */
final class CountInLoopRule extends AbstractCodeSmellRule
{
    public const string NAME = 'code-smell.count-in-loop';
    protected const string DESCRIPTION = 'Detects count() calls in loop conditions';
    protected const string SMELL_TYPE = 'count_in_loop';
    protected const string MESSAGE_TEMPLATE = 'count() in loop condition detected - store in variable before loop';
    protected const ?string RECOMMENDATION = 'Store the count result in a variable before the loop.';
}
