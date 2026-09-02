<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract\Directive;

/**
 * How much of the rule layer a counterfactual execution runs.
 *
 * A `@qmx-threshold` addresses exactly one rule by exact name (ADR 0024 §2),
 * so the outcome it can move is that rule's. {@see Narrow} executes only the
 * addressed producer and compares against a baseline taken the same way; on
 * this tree that is the difference between eight rule executions and
 * thirty-three whole ones.
 *
 * {@see Full} is not a fallback and not a legacy path. It is the other side of
 * the control that licenses the narrowing: the claim "removing a directive of
 * rule X cannot move a finding of rule Y" is measured by sweeping the same
 * tree both ways and comparing verdict for verdict. A control that could only
 * be run by editing the code would be a control run once.
 */
enum DirectiveSweepScope: string
{
    /** Execute only the producer the directive addresses. */
    case Narrow = 'narrow';

    /** Execute every rule the run's selection leaves enabled. */
    case Full = 'full';
}
