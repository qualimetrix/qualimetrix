<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline\Filter;

use InvalidArgumentException;
use Qualimetrix\Baseline\Baseline;
use Qualimetrix\Baseline\BaselineEntry;
use Qualimetrix\Baseline\BaselineEntryMode;
use Qualimetrix\Baseline\BaselineIdentity;
use Qualimetrix\Baseline\GroupAcceptance;
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
 * A group is accepted when every current magnitude is finite and — per
 * {@see GroupAcceptance} — no level of severity holds more current members
 * than the stored group held. That type owns the cumulative rule and the
 * argument for counting members instead of pairing them by rank; this class
 * only decides *which* comparison applies (magnitude vs. occurrence) and
 * what happens around it (applicability, `mode`, promotion).
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
        return $this->judgeAll($violations)->result;
    }

    /**
     * Judges every group in one pass over one list and returns everything
     * this stage can say about it: the filtered/promoted result, the entries
     * whose identity did not appear (§5.7), and the entries the loader could
     * not apply at all (§6). See {@see CeilingOutcome} for why bundling these
     * together — rather than a second call reading `apply()`'s own input a
     * second time — is what makes the two unable to disagree.
     *
     * @param list<Violation> $violations
     */
    public function judgeAll(array $violations): CeilingOutcome
    {
        /** @var array<int, GroupCeilingVerdict> $verdictByIndex */
        $verdictByIndex = [];
        $measuredIdentityKeys = [];

        foreach (self::groupByIdentity($violations) as $group) {
            $measuredIdentityKeys[$group['identity']->key()] = true;
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

        return new CeilingOutcome(
            result: new ViolationFilterStageResult(ViolationFilterStage::Baseline, $kept, $removed),
            staleEntries: $this->baseline->staleEntries(array_keys($measuredIdentityKeys)),
            inertEntries: $this->baseline->inertEntries,
        );
    }

    /**
     * The scope the loaded baseline file recorded — `check` compares it
     * against the run's own scope to report a mismatch (§5.7), never to fail
     * on one.
     *
     * @return list<string>
     */
    public function baselineScope(): array
    {
        return $this->baseline->scope;
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
     * entry recorded — {@see GroupAcceptance::countWithin()}.
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

        return GroupAcceptance::countWithin(\count($group), $entry->count)
            ? GroupCeilingVerdict::accepted()
            : GroupCeilingVerdict::breached(self::levelOf($entry));
    }

    /**
     * The cumulative rule over both magnitude vectors —
     * {@see GroupAcceptance::magnitudesWithin()}.
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

        return GroupAcceptance::magnitudesWithin($current, $stored, $direction)
            ? GroupCeilingVerdict::accepted()
            : GroupCeilingVerdict::breached(self::levelOf($entry));
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
