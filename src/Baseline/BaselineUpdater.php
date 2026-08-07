<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

use InvalidArgumentException;
use LogicException;
use Qualimetrix\Core\Time\ClockInterface;
use Qualimetrix\Core\Violation\ChannelDeclaration;
use Qualimetrix\Core\Violation\ChannelDeclarationRegistryInterface;
use Qualimetrix\Core\Violation\ChannelShape;
use Qualimetrix\Core\Violation\Violation;

/**
 * `baseline:update`: a direction-aware monotonic tightening of an existing
 * baseline against a fresh measured run (§7 of the baseline-ceiling plan).
 *
 * Three rules, applied per entry, none of them a comparison this class
 * re-derives — {@see GroupAcceptance} already states the acceptance test for
 * the ceiling, and §7 requires `update` to call the identical primitive
 * rather than write a second definition of "not more permissive":
 *
 * - **An identity absent from the measured set is left untouched.** A
 *   vanished group is `baseline:cleanup`'s business, not a reason to rewrite
 *   an entry to nothing.
 * - **`update` never adds an identity.** Only entries the loaded baseline
 *   already held are considered; a measured finding with no entry is
 *   ignored.
 * - **A measured group replaces the stored one exactly when
 *   {@see GroupAcceptance} accepts it against the stored one** — the same
 *   test {@see \Qualimetrix\Baseline\Filter\BaselineCeilingStage} applies at
 *   `check` time, evaluated here instead. Every other measured group is
 *   refused and the entry is written back exactly as it was: a refusal never
 *   means "clamp to whatever is safe", because a partial write disguised as
 *   a refusal would be a second, undocumented acceptance rule.
 *
 * **Why a magnitude channel needs no separate count-only check.**
 * {@see GroupAcceptance::magnitudesWithin()}, evaluated at the current
 * group's own least-bad magnitude, already covers the whole current group
 * (§5.1's own proof of the cumulative rule): a current group larger than the
 * stored one fails the comparison before any position-by-position
 * difference is even considered. A bug class once existed here by treating
 * "count may only shrink" as a second, `higher`-only rule (§7 names it as
 * the defect 10.1 was written to fix); calling one primitive for both
 * magnitudes and their implied count makes that recurrence impossible.
 * {@see \Qualimetrix\Tests\Unit\Baseline\BaselineUpdaterTest} still pins a
 * `lower`-channel count-widening case directly, because a proof is only as
 * good as the code that keeps calling the primitive it is a proof about.
 *
 * `mode` is preserved verbatim on every written entry. `update` does not
 * read it to decide whether to tighten: `mode: suppress`'s "accept this
 * identity regardless of magnitude and count" (§5.1) is the *ceiling*'s
 * reading of an entry at `check` time, not a license for `update` to
 * overwrite a suppressed entry's recorded numbers with something worse than
 * what is already on file. Inert entries are carried forward exactly as
 * loaded — `update` does not read {@see Baseline::$inertEntries} at all, and
 * must not lose a line an unrelated defect put there; `cleanup` is the only
 * command with an opinion about them (§6).
 */
final readonly class BaselineUpdater
{
    public function __construct(
        private ChannelDeclarationRegistryInterface $declarations,
        private ClockInterface $clock,
    ) {}

    /**
     * @param list<Violation> $measured the run's measured set (§5.5)
     * @param list<string> $scope the paths this run analysed; {@see Baseline}
     *                            normalizes it on the way in
     */
    public function update(Baseline $baseline, array $measured, array $scope): BaselineUpdateResult
    {
        $groups = self::groupByIdentity($measured);

        $entries = [];
        $outcomes = [];

        foreach ($baseline->entries as $entry) {
            $group = $groups[$entry->identity->key()] ?? null;

            if ($group === null) {
                $entries[] = $entry;
                $outcomes[] = BaselineEntryUpdateOutcome::skipped($entry->identity);

                continue;
            }

            [$written, $outcome] = $this->reconcile($entry, $group);
            $entries[] = $written;
            $outcomes[] = $outcome;
        }

        $updated = new Baseline(
            generated: $this->clock->now(),
            scope: $scope,
            entries: $entries,
            inertEntries: $baseline->inertEntries,
            sourceContentHash: $baseline->sourceContentHash,
        );

        return new BaselineUpdateResult($updated, $outcomes);
    }

    /**
     * Decides one entry, in the order applicability requires: whether the
     * entry can be compared at all is settled before anything about the
     * measured group is read, mirroring the ceiling stage's own ordering.
     *
     * @param list<Violation> $group every measured finding sharing the entry's identity
     *
     * @return array{BaselineEntry, BaselineEntryUpdateOutcome}
     */
    private function reconcile(BaselineEntry $entry, array $group): array
    {
        $declaration = $this->declarations->declarationFor($entry->identity->channel);

        if ($declaration === null) {
            return [$entry, BaselineEntryUpdateOutcome::refused($entry->identity, BaselineUpdateRefusalReason::UndeclaredChannel)];
        }

        if ($declaration->shape !== $entry->shape()) {
            return [$entry, BaselineEntryUpdateOutcome::refused($entry->identity, BaselineUpdateRefusalReason::ShapeMismatch)];
        }

        return $entry->shape() === ChannelShape::Occurrence
            ? $this->reconcileOccurrence($entry, $group)
            : $this->reconcileMagnitude($entry, $declaration, $group);
    }

    /**
     * One level, no magnitudes: {@see GroupAcceptance::countWithin()} is the
     * whole comparison.
     *
     * @param list<Violation> $group
     *
     * @return array{BaselineEntry, BaselineEntryUpdateOutcome}
     */
    private function reconcileOccurrence(BaselineEntry $entry, array $group): array
    {
        $currentCount = \count($group);

        if (!GroupAcceptance::countWithin($currentCount, $entry->count)) {
            return [$entry, BaselineEntryUpdateOutcome::refused($entry->identity, BaselineUpdateRefusalReason::Worsened)];
        }

        $written = new BaselineEntry($entry->identity, null, $currentCount, $entry->mode);

        return [$written, BaselineEntryUpdateOutcome::updated($entry->identity)];
    }

    /**
     * @param list<Violation> $group
     *
     * @return array{BaselineEntry, BaselineEntryUpdateOutcome}
     */
    private function reconcileMagnitude(BaselineEntry $entry, ChannelDeclaration $declaration, array $group): array
    {
        $stored = $entry->magnitudes;

        if ($stored === null) {
            // Unreachable: BaselineEntry::shape() is Magnitude exactly when
            // magnitudes is non-null, and reconcile() already matched
            // $entry->shape() against $declaration->shape before calling
            // here. Kept only to narrow $stored's type for static analysis.
            throw new LogicException('BaselineEntry::shape() reported Magnitude with no magnitudes stored.');
        }

        $direction = $declaration->direction;

        if ($direction === null) {
            // Unreachable: ChannelDeclaration's own constructor refuses to
            // exist as a Magnitude declaration without a WorseDirection.
            throw new LogicException('A magnitude ChannelDeclaration was built without a WorseDirection.');
        }

        $current = self::currentMagnitudes($group);

        if ($current === null) {
            return [$entry, BaselineEntryUpdateOutcome::refused($entry->identity, BaselineUpdateRefusalReason::CurrentMagnitudeUnavailable)];
        }

        if (!GroupAcceptance::magnitudesWithin($current, $stored, $direction)) {
            return [$entry, BaselineEntryUpdateOutcome::refused($entry->identity, BaselineUpdateRefusalReason::Worsened)];
        }

        $written = new BaselineEntry($entry->identity, $current, \count($current), $entry->mode);

        return [$written, BaselineEntryUpdateOutcome::updated($entry->identity)];
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
                // NaN or infinity: not a boundary, so nothing to compare against.
                return null;
            }
        }

        return $magnitudes;
    }

    /**
     * @param list<Violation> $violations
     *
     * @return array<string, list<Violation>> identity key => its group
     */
    private static function groupByIdentity(array $violations): array
    {
        $groups = [];

        foreach ($violations as $violation) {
            $groups[BaselineIdentity::forViolation($violation)->key()][] = $violation;
        }

        return $groups;
    }
}
