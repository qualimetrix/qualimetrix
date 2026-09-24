<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use Qualimetrix\Analysis\Finding\Contract\AcceptedLevel;

/**
 * The baseline half of an {@see EffectiveBoundary}: the state of the entry
 * addressing the identity, what it accepted, and what the measured set
 * currently compares against it.
 *
 * Both numbers are required by design (ADR 0017):
 * a channel's magnitude can change scale without the channel itself
 * changing — `coupling.cbo` changes meaning with the `scope` option, a
 * computed metric's formula or `inverted` flag can be rewritten — so the
 * stored side alone cannot be trusted to still mean what it meant at
 * capture. Printing {@see $currentMagnitudes} next to {@see $accepted} is
 * how that drift becomes visible where a user would look for it, rather
 * than staying a silent over-acceptance.
 *
 * The numbers alone do not say whether the ceiling uses them, and three
 * states reverse the reading of the pair: an entry the loader turned inert
 * ({@see $inert}), an entry that accepts whatever is reported
 * ({@see $mode}), and a group the ceiling declines to compare because a
 * member has no finite number ({@see $membersWithoutMagnitude}). Each is
 * carried as the fact the ceiling reads, so the explanation never has to
 * infer it from an absence.
 */
final readonly class EffectiveBoundaryBaselineSource
{
    /**
     * @param ?AcceptedLevel $accepted the level {@see BaselineEntry} recorded at capture;
     *                                 `null` exactly when {@see $inert} is set, since an inert
     *                                 line has no level the ceiling could read
     * @param ?list<float> $currentMagnitudes the measured set's current magnitudes for this
     *                                        identity's group, normalised the way the stored
     *                                        ones were ({@see BaselineEntry::normalizeMagnitude()});
     *                                        `null` unless the entry is magnitude-shaped. Members
     *                                        without a finite value are left out and counted in
     *                                        {@see $membersWithoutMagnitude} instead
     * @param int $currentCount how many findings currently share this identity, whatever
     *                          their shape — 0 when the group is currently empty
     * @param int $membersWithoutMagnitude members of a magnitude group reporting no finite
     *                                     number; above zero, the ceiling reports the group
     *                                     rather than comparing it (unless {@see $mode} waives
     *                                     the comparison)
     * @param bool $producerRan whether the rule producing this channel ran in this invocation
     *                          ({@see RunRuleCoverage}); when it did not, an empty group says
     *                          nothing about the code
     */
    private function __construct(
        public ?AcceptedLevel $accepted,
        public ?array $currentMagnitudes,
        public int $currentCount,
        public ?BaselineEntryMode $mode,
        public int $membersWithoutMagnitude,
        public ?InertBaselineEntry $inert,
        public bool $producerRan,
    ) {}

    /**
     * @param ?list<float> $currentMagnitudes
     */
    public static function applicable(
        BaselineEntry $entry,
        ?array $currentMagnitudes,
        int $currentCount,
        int $membersWithoutMagnitude,
    ): self {
        return new self(
            new AcceptedLevel($entry->magnitudes, $entry->count),
            $currentMagnitudes,
            $currentCount,
            $entry->mode,
            $membersWithoutMagnitude,
            null,
            true,
        );
    }

    public static function inert(InertBaselineEntry $entry, int $currentCount): self
    {
        return new self(null, null, $currentCount, null, 0, $entry, true);
    }

    /**
     * The same source, read in a run that did not execute the rule producing
     * its channel.
     */
    public function unmeasured(): self
    {
        return new self(
            $this->accepted,
            $this->currentMagnitudes,
            $this->currentCount,
            $this->mode,
            $this->membersWithoutMagnitude,
            $this->inert,
            false,
        );
    }

}
