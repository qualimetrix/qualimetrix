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
 * {@see \Qualimetrix\Analysis\Policy\Baseline\Ceiling\BaselineCeilingStage} documents for its
 * own applicability checks.
 */
enum BaselineUpdateRefusalReason: string
{
    /** No rule declares the channel any more, so nothing knows how to compare it. */
    case UndeclaredChannel = 'undeclared-channel';

    case RecordedPathsDiffer = 'recorded-paths-differ';

    case ComparisonMetadataUnknown = 'comparison-metadata-unknown';

    case RequiredGroupUnavailable = 'required-group-unavailable';

    /**
     * The channel declares itself a configuration error: `update` refuses to
     * re-record it, exactly as `generate` refuses to capture it, so that a
     * misconfigured run cannot ratchet its own misconfiguration into the file.
     */
    case ConfigurationErrorChannel = 'configuration-error-channel';

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

    private const array DESCRIPTIONS = [
        'recorded-paths-differ' => 'recording exclusions requires exactly the recorded paths, even under --force',
        'comparison-metadata-unknown' => 'the exclusion comparison cannot be proved complete for every required group',
        'required-group-unavailable' => 'an exclusion-affected group is absent or has no complete finite measurement',
        'undeclared-channel' => 'no rule declares the channel any more',
        'configuration-error-channel' => 'the channel reports a configuration error, which cannot be accepted as debt',
        'shape-mismatch' => 'the entry no longer matches the channel\'s declared shape',
        'current-magnitude-unavailable' => 'the measured group reports no finite magnitude',
        'worsened' => 'the measured group is not accepted against the stored one',
        'worsened-under-suppression' => 'the measured group is not accepted against the stored one, '
            . 'so the recorded numbers are kept; mode: suppress means this entry suppresses either way',
    ];

    public function description(): string
    {
        return self::DESCRIPTIONS[$this->value];
    }
}
