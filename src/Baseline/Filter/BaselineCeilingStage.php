<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline\Filter;

use InvalidArgumentException;
use Qualimetrix\Baseline\Baseline;
use Qualimetrix\Baseline\BaselineEntry;
use Qualimetrix\Baseline\BaselineEntryMode;
use Qualimetrix\Baseline\BaselineIdentity;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Violation\AcceptedLevel;
use Qualimetrix\Core\Violation\ChannelDeclarationRegistryInterface;
use Qualimetrix\Core\Violation\ChannelShape;
use Qualimetrix\Core\Violation\Filter\ViolationFilterStage;
use Qualimetrix\Core\Violation\Filter\ViolationFilterStageInterface;
use Qualimetrix\Core\Violation\Filter\ViolationFilterStageResult;
use Qualimetrix\Core\Violation\Violation;

/**
 * Applies a baseline as a ceiling: each entry bounds the group of findings
 * that share its identity (§5.1 of the baseline-ceiling plan).
 *
 * Three things can happen to a group, and the stage is transforming rather
 * than filtering because of the second (see {@see GroupCeilingVerdict}):
 * accepted groups are dropped, measured breaches are reported promoted to
 * Error, and everything else is reported untouched.
 *
 * ## The acceptance rule
 *
 * A group is accepted when every current magnitude is finite and **no level
 * of severity holds more current members than the stored group held**. For
 * each value `t`, the number of current members at least as bad as `t` must
 * not exceed the number of stored members at least as bad as `t`, where "at
 * least as bad" is `>=` on a `higher` channel and `<=` on a `lower` one.
 * Only the levels the current group itself supplies need checking: the test
 * can only fail at a level some current member reaches.
 *
 * **It counts members; it never pairs them.** A rank comparison has an end
 * to align from, and each end is wrong in one direction. Stored `[100, 40]`
 * on a `higher` channel with the 40-line duplicate deleted and nothing else
 * touched: aligning from the best end reads `100` against `40` and reports a
 * breach on a symbol nobody touched — the tool answering a pure repair with
 * a red build. Aligning from the worst end assumes the opposite. Counting
 * assumes nothing. (The cumulative form is provably equivalent to worst-end
 * alignment and additionally subsumes the count condition, which is why
 * there is one rule here and not two.)
 *
 * On an `occurrence` channel there are no magnitudes and there is one level:
 * the group must hold no more members than `count`. That is the same
 * sentence with the severity axis collapsed, not a second mechanism — and
 * the number such a channel's findings report, fixed marker or real value,
 * is ignored by contract.
 *
 * ## The governing invariant
 *
 * *An entry that cannot be applied does not suppress — and does not promote
 * either.* An unknown channel, a declaration missing its shape or direction,
 * an entry whose shape disagrees with its channel's in either direction, a
 * `magnitude` group where some member reports no finite number, an entry the
 * loader could not read, a renamed symbol whose entry is simply not found:
 * each reports the findings at the severity their own rule gave them. None
 * of them is evidence that the debt got worse, and a build that went red on
 * one would be punishing a user for a stale file.
 *
 * The invariant has no `mode` exception, which is why {@see judge()} settles
 * applicability before it reads one: `suppress` waives the comparison, not
 * the question of whether the entry bounds this channel at all.
 *
 * ## Both sides of the comparison are normalised
 *
 * The stored side is rounded by {@see BaselineEntry}'s constructor. The
 * recomputed side is rounded here, through the same
 * {@see BaselineEntry::normalizeMagnitude()}. The zero tolerance of the
 * comparison is unsound otherwise: a raw recomputed value and its rounded
 * stored copy can differ below the sixth decimal and read as a breach with
 * no code change.
 */
final readonly class BaselineCeilingStage implements ViolationFilterStageInterface
{
    public function __construct(
        private Baseline $baseline,
        private ChannelDeclarationRegistryInterface $declarations,
    ) {}

    public function stage(): ViolationFilterStage
    {
        return ViolationFilterStage::Baseline;
    }

    public function apply(array $violations): ViolationFilterStageResult
    {
        /** @var array<int, GroupCeilingVerdict> $verdictByIndex */
        $verdictByIndex = [];

        foreach (self::groupByIdentity($violations) as $group) {
            $verdict = $this->judge($group['identity'], $group['violations']);

            foreach ($group['indexes'] as $index) {
                $verdictByIndex[$index] = $verdict;
            }
        }

        $kept = [];
        $removed = [];

        foreach ($violations as $index => $violation) {
            $verdict = $verdictByIndex[$index];

            if ($verdict->suppresses()) {
                $removed[] = $violation;

                continue;
            }

            $kept[] = $verdict->breachedLevel !== null
                ? $violation->reportedAsBreach($verdict->breachedLevel)
                : $violation;
        }

        return new ViolationFilterStageResult(ViolationFilterStage::Baseline, $kept, $removed);
    }

    /**
     * Entries whose identity did not appear in the measured set — the debt
     * that was paid off, or silenced, since capture (§5.7).
     *
     * **The argument must be the very list handed to {@see apply()}.** The
     * predicate is "absent from the set the ceiling measured", and the set
     * the ceiling measured is its own input: passing the output instead
     * would report every accepted entry as stale, and passing a differently
     * filtered list is how the previous `public static` version came to be
     * evaluated twice per run over two sets that only agreed by accident.
     * It is an instance method on the stage precisely so there is one
     * object, one baseline and one set to pass.
     *
     * **This is one object short of making the mistake impossible, and the
     * remaining step is deliberately not taken here.** The airtight shape is
     * a single call that returns the filtered list and the stale entries
     * together, so the two cannot be computed over different sets at all —
     * an outcome type owned by this component, since `list<BaselineEntry>`
     * cannot travel in {@see ViolationFilterStageResult} (that type lives in
     * `Core`, which may not depend on `Baseline`). Introducing it means
     * changing the one caller, which sits in `Infrastructure`; until that
     * caller moves, a second entry point here would be two surfaces where
     * the danger is having two. See {@see ViolationFilterStageInterface} for
     * why the caller reaches this method through a downcast.
     *
     * @param list<Violation> $measured the exact list {@see apply()} was given
     *
     * @return list<BaselineEntry>
     */
    public function staleEntriesOver(array $measured): array
    {
        $keys = [];

        foreach ($measured as $violation) {
            $keys[BaselineIdentity::forViolation($violation)->key()] = true;
        }

        return $this->baseline->staleEntries(array_keys($keys));
    }

    /**
     * Decides one group, in the order the governing invariant requires:
     * **whether the entry applies at all is settled before anything the
     * entry itself asks for.**
     *
     * The order is not incidental. `mode: suppress` waives the comparison of
     * magnitudes and count — it does not waive the question of whether this
     * entry bounds this channel in the first place. §5.4 says a channel
     * declaring neither shape nor direction has entries that do not suppress,
     * and §6 says an entry addressing an undeclared channel or mismatching
     * its channel's shape does not suppress; neither sentence carries a `mode`
     * exception. Reading `mode` first would make `suppress` the one way to
     * silence a finding no declaration covers, and it would fail in the
     * silent direction — the worse of the two, since the fail-safe rule
     * exists precisely to keep an inapplicable entry from hiding debt.
     *
     * Today nothing reaches here in that state: the loader rejects an
     * undeclared channel and a shape mismatch before an entry is built,
     * independently of `mode`. The stage does not lean on that, because a
     * {@see Baseline} assembled in memory — as the lifecycle commands do —
     * never passes through the loader at all.
     *
     * @param list<Violation> $group
     */
    private function judge(BaselineIdentity $identity, array $group): GroupCeilingVerdict
    {
        $entry = $this->baseline->findByIdentity($identity);

        if ($entry === null) {
            // No entry at all: a new finding, or a renamed symbol whose entry
            // is stranded under the old name. Neither is a breach.
            return GroupCeilingVerdict::reported();
        }

        $declaration = $this->declarations->declarationFor($identity->channel);

        if ($declaration === null) {
            return GroupCeilingVerdict::reported();
        }

        return $declaration->shape === ChannelShape::Occurrence
            ? self::judgeOccurrence($entry, $group)
            : self::judgeMagnitude($entry, $declaration->direction, $group);
    }

    /**
     * One level, no magnitudes: the group must hold no more members than the
     * entry recorded.
     *
     * @param list<Violation> $group
     */
    private static function judgeOccurrence(BaselineEntry $entry, array $group): GroupCeilingVerdict
    {
        // A stored magnitude list on an occurrence channel is a shape
        // mismatch: the entry claims a boundary the channel's number cannot
        // be compared against. Checked before `mode`, per {@see judge()}.
        if ($entry->magnitudes !== null) {
            return GroupCeilingVerdict::reported();
        }

        if ($entry->mode === BaselineEntryMode::Suppress) {
            return GroupCeilingVerdict::accepted();
        }

        return \count($group) <= $entry->count
            ? GroupCeilingVerdict::accepted()
            : GroupCeilingVerdict::breached(self::levelOf($entry));
    }

    /**
     * The cumulative rule over both magnitude vectors.
     *
     * Three ways to arrive with nothing comparable, all resolving to
     * "reported": the entry stores no magnitudes though its channel says it
     * should (a magnitude channel bounded only by a count would silently
     * accept unbounded growth), the declaration carries no direction, or
     * some member of the group reports no finite number.
     *
     * The first two are applicability, so they precede `mode`; the third is
     * the comparison itself, so `mode: suppress` waives it — an entry that
     * says "accept this identity regardless of magnitude and count" (§5.1)
     * has no use for the group's numbers.
     *
     * @param list<Violation> $group
     */
    private static function judgeMagnitude(
        BaselineEntry $entry,
        ?WorseDirection $direction,
        array $group,
    ): GroupCeilingVerdict {
        $stored = $entry->magnitudes;

        if ($stored === null || $direction === null) {
            return GroupCeilingVerdict::reported();
        }

        if ($entry->mode === BaselineEntryMode::Suppress) {
            return GroupCeilingVerdict::accepted();
        }

        $current = self::currentMagnitudes($group);

        if ($current === null) {
            return GroupCeilingVerdict::reported();
        }

        return self::isWithin($current, $stored, $direction)
            ? GroupCeilingVerdict::accepted()
            : GroupCeilingVerdict::breached(self::levelOf($entry));
    }

    /**
     * The cumulative rule of §5.1, evaluated at every level the current group
     * supplies.
     *
     * @param list<float> $current
     * @param list<float> $stored
     */
    private static function isWithin(array $current, array $stored, WorseDirection $direction): bool
    {
        foreach ($current as $level) {
            $currentAtLevel = self::countAtLeastAsBadAs($current, $level, $direction);
            $storedAtLevel = self::countAtLeastAsBadAs($stored, $level, $direction);

            if ($currentAtLevel > $storedAtLevel) {
                return false;
            }
        }

        return true;
    }

    /**
     * How many of these magnitudes are at least as bad as `$level`.
     *
     * "At least as bad" is the negation of "the level is worse than this
     * magnitude", so the direction's own operator answers it and no sign
     * handling is re-derived here. The epsilon stays at its `0.0` default:
     * both sides are already normalised to six decimal places, which is what
     * earns the zero.
     *
     * @param list<float> $magnitudes
     */
    private static function countAtLeastAsBadAs(array $magnitudes, float $level, WorseDirection $direction): int
    {
        $count = 0;

        foreach ($magnitudes as $magnitude) {
            if (!$direction->isWorse($level, $magnitude)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * The group's magnitudes, normalised the way the stored ones were, or
     * `null` when some member reports no usable number.
     *
     * @param list<Violation> $group
     *
     * @return ?list<float>
     */
    private static function currentMagnitudes(array $group): ?array
    {
        $magnitudes = [];

        foreach ($group as $violation) {
            if ($violation->metricValue === null) {
                return null;
            }

            try {
                $magnitudes[] = BaselineEntry::normalizeMagnitude($violation->metricValue);
            } catch (InvalidArgumentException) {
                // NaN or infinity: not a boundary, and not a breach either.
                return null;
            }
        }

        return $magnitudes;
    }

    private static function levelOf(BaselineEntry $entry): AcceptedLevel
    {
        return new AcceptedLevel($entry->magnitudes, $entry->count);
    }

    /**
     * Groups the run's findings by identity while remembering where each one
     * sat, so the output preserves the input order rather than the grouping
     * order.
     *
     * @param list<Violation> $violations
     *
     * @return array<string, array{identity: BaselineIdentity, violations: list<Violation>, indexes: list<int>}>
     */
    private static function groupByIdentity(array $violations): array
    {
        $groups = [];

        foreach ($violations as $index => $violation) {
            $identity = BaselineIdentity::forViolation($violation);
            $key = $identity->key();

            $groups[$key] ??= ['identity' => $identity, 'violations' => [], 'indexes' => []];
            $groups[$key]['violations'][] = $violation;
            $groups[$key]['indexes'][] = $index;
        }

        return $groups;
    }
}
