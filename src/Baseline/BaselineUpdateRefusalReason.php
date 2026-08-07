<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

/**
 * Why `baseline:update` refused to tighten a measured entry (§7 of the
 * baseline-ceiling plan).
 *
 * The first two mirror {@see InertEntryReason}'s fail-safe direction: an
 * entry `update` cannot compare is left exactly as it was, never widened and
 * never narrowed by a guess. They are reachable here even though the loader
 * already refuses such entries on their way out of a file, because a
 * lifecycle command may assemble a {@see Baseline} in memory without going
 * through the loader at all — the same reachability
 * {@see \Qualimetrix\Baseline\Filter\BaselineCeilingStage} documents for its
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

    /** The measured group is not accepted against the stored one (§5.1) — it is worse, not better. */
    case Worsened = 'worsened';

    public function description(): string
    {
        return match ($this) {
            self::UndeclaredChannel => 'no rule declares the channel any more',
            self::ShapeMismatch => 'the entry no longer matches the channel\'s declared shape',
            self::CurrentMagnitudeUnavailable => 'the measured group reports no finite magnitude',
            self::Worsened => 'the measured group is not accepted against the stored one',
        };
    }
}
