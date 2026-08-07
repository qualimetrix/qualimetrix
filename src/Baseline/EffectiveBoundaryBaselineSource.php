<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

use Qualimetrix\Core\Violation\AcceptedLevel;

/**
 * The baseline half of an {@see EffectiveBoundary}: what was accepted, and
 * what the measured set currently compares against it.
 *
 * Both numbers are required by design (§13.5 of the baseline-ceiling plan):
 * a channel's magnitude can change scale without the channel itself
 * changing — `coupling.cbo` changes meaning with the `scope` option, a
 * computed metric's formula or `inverted` flag can be rewritten — so the
 * stored side alone cannot be trusted to still mean what it meant at
 * capture. Printing {@see $currentMagnitudes} next to {@see $accepted} is
 * how that drift becomes visible where a user would look for it, rather
 * than staying a silent over-acceptance.
 */
final readonly class EffectiveBoundaryBaselineSource
{
    /**
     * @param AcceptedLevel $accepted the level {@see BaselineEntry} recorded at capture
     * @param ?list<float> $currentMagnitudes the measured set's current magnitudes for this
     *                                        identity's group, normalised the way the stored
     *                                        ones were ({@see BaselineEntry::normalizeMagnitude()});
     *                                        `null` on an `occurrence` entry, where only
     *                                        {@see $currentCount} is meaningful. A member whose
     *                                        current value is unusable (absent or non-finite) is
     *                                        left out rather than making the whole list `null` —
     *                                        this is a report, not a re-judgement of acceptance
     * @param int $currentCount how many findings currently share this identity, whatever
     *                          their shape — 0 when the group is currently empty
     */
    public function __construct(
        public AcceptedLevel $accepted,
        public ?array $currentMagnitudes,
        public int $currentCount,
    ) {}
}
