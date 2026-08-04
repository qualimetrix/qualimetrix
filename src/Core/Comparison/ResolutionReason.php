<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Comparison;

/**
 * Why a recorded finding no longer appears.
 *
 * Qualifies {@see ComparisonStatus::Resolved} rather than splitting it into
 * two statuses, because the distinction changes what may be done with the
 * entry, not what the entry is.
 *
 * Decided by comparing **boundaries, not values**. When a finding resolves
 * there is no current observation to compare — no violation was emitted, so
 * nothing was measured. What is available is the boundary recorded with the
 * entry and the boundary in force now.
 *
 * This type carries only the two case names and what they mean — the
 * question "may a cleanup command remove an entry resolved for this reason"
 * is asked by exactly one consumer, Baseline's cleanup command, so per
 * ADR 0016's duplication test it lives there rather than on this enum. See
 * {@see ComparisonStatus} for the fuller account of why a lifecycle-policy
 * method does not belong on a cross-cutting vocabulary type.
 */
enum ResolutionReason: string
{
    /**
     * The boundary in force now is no more permissive than the recorded one,
     * so the absence of a finding proves the measurement moved past the same
     * line. Safe to remove from the record.
     */
    case Fixed = 'fixed';

    /**
     * The boundary in force now is more permissive than the recorded one, so
     * the absence proves nothing about the code — only that the line moved.
     * Reported and retained, so that re-tightening the boundary restores the
     * originally recorded debt instead of re-admitting today's worse values
     * as a brand-new finding.
     */
    case Policy = 'policy';
}
