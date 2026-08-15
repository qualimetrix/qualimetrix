<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

/**
 * Why `baseline:update` refused to tighten a measured entry (ADR 0017).
 *
 * The first two mirror {@see InertEntryReason}'s fail-safe direction: an
 * entry `update` cannot compare is left exactly as it was, never widened and
 * never narrowed by a guess. They are reachable here even though the loader
 * already refuses such entries on their way out of a file, because a
 * lifecycle command may assemble a {@see Baseline} in memory without going
 * through the loader at all — the same reachability
 * {@see \Qualimetrix\Analysis\Policy\Baseline\Filter\BaselineCeilingStage} documents for its
 * own applicability checks.
 */
enum BaselineUpdateRefusalReason: string
{
    /** No rule declares the channel any more, so nothing knows how to compare it. */
    case UndeclaredChannel = 'undeclared-channel';

    /** The entry's own shape and the channel's currently declared shape disagree. */
    case ShapeMismatch = 'shape-mismatch';

    /** A member of the measured group reports no finite magnitude. */
    case CurrentMagnitudeUnavailable = 'current-magnitude-unavailable';

    /** The measured group is not accepted against the stored one (ADR 0017) — it is worse, not better. */
    case Worsened = 'worsened';

    /**
     * The same comparison declined, on an entry carrying `mode: suppress`.
     *
     * A suppressed entry is tested exactly like any other — `update` must not
     * write a worse group into it, or `update` would become a way to widen an
     * acceptance (ADR 0017). But the *consequence* is not the same, and "worsened"
     * describes it wrongly: `mode: suppress` accepts this identity regardless
     * of magnitude and count (ADR 0017), so `check` never compares the numbers
     * this refusal is about and the build does not go red. Telling a user
     * "worsened" where nothing they can observe worsened sends them looking
     * for a failure that is not there.
     */
    case WorsenedUnderSuppression = 'worsened-under-suppression';

    public function description(): string
    {
        return match ($this) {
            self::UndeclaredChannel => 'no rule declares the channel any more',
            self::ShapeMismatch => 'the entry no longer matches the channel\'s declared shape',
            self::CurrentMagnitudeUnavailable => 'the measured group reports no finite magnitude',
            self::Worsened => 'the measured group is not accepted against the stored one',
            self::WorsenedUnderSuppression => 'the measured group is not accepted against the stored one, '
                . 'so the recorded numbers are kept; mode: suppress means this entry suppresses either way',
        };
    }
}
