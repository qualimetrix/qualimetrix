<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Coverage;

/**
 * Whether a run can prove that a given scope was evaluated.
 *
 * Three values rather than a boolean, because "not evaluated" and "cannot
 * tell" lead to the same conclusion for the reader but to different
 * diagnostics: the first names a cause the user chose, the second admits the
 * run lost track. Collapsing them would make an interrupted run
 * indistinguishable from a deliberately narrowed one.
 */
enum ScopeCoverageStatus: string
{
    /** Discovered, parsed, not excluded from evaluation, rule enabled, rule completed. */
    case Evaluated = 'evaluated';

    /** Provably not evaluated, for a nameable reason. */
    case NotEvaluated = 'not-evaluated';

    /** The run cannot establish either way — a partial, failed, or interrupted run. */
    case Indeterminate = 'indeterminate';

    /**
     * Whether this status is positive proof of evaluation.
     *
     * Only a proven evaluation lets absence of a finding be read as its
     * disappearance; under either other value, silence carries no
     * information.
     */
    public function provesEvaluation(): bool
    {
        return $this === self::Evaluated;
    }
}
