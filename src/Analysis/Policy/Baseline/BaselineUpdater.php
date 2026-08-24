<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use InvalidArgumentException;
use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Core\Time\ClockInterface;

/**
 * `baseline:update`: a direction-aware monotonic tightening of an existing
 * baseline against a fresh measured run (ADR 0017).
 *
 * Three rules, applied per entry, none of them a comparison this class
 * re-derives — {@see GroupAcceptance} already states the acceptance test for
 * the ceiling, and ADR 0017 requires `update` to call the identical primitive
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
 *   test {@see \Qualimetrix\Analysis\Policy\Baseline\Filter\BaselineCeilingStage} applies at
 *   `check` time, evaluated here instead. Every other measured group is
 *   refused and the entry is written back exactly as it was: a refusal never
 *   means "clamp to whatever is safe", because a partial write disguised as
 *   a refusal would be a second, undocumented acceptance rule.
 *
 * **Why a magnitude channel needs no separate count-only check.**
 * {@see GroupAcceptance::magnitudesWithin()}, evaluated at the current
 * group's own least-bad magnitude, already covers the whole current group
 * (ADR 0017's proof of the cumulative rule): a current group larger than the
 * stored one fails the comparison before any position-by-position
 * difference is even considered. A bug class once existed here by treating
 * "count may only shrink" as a second, `higher`-only rule (ADR 0017 names it as
 * the defect 10.1 was written to fix); calling one primitive for both
 * magnitudes and their implied count makes that recurrence impossible.
 * {@see \Qualimetrix\Tests\Analysis\Policy\Baseline\Unit\BaselineUpdaterTest} still pins a
 * `lower`-channel count-widening case directly, because a proof is only as
 * good as the code that keeps calling the primitive it is a proof about.
 *
 * `mode` is preserved verbatim on every written entry. `update` does not
 * read it to decide whether to tighten: `mode: suppress`'s "accept this
 * identity regardless of magnitude and count" (ADR 0017) is the *ceiling*'s
 * reading of an entry at `check` time, not a license for `update` to
 * overwrite a suppressed entry's recorded numbers with something worse than
 * what is already on file. Inert entries are carried forward exactly as
 * loaded — `update` does not read {@see Baseline::$inertEntries} at all, and
 * must not lose a line an unrelated defect put there; `cleanup` is the only
 * command with an opinion about them (ADR 0017).
 */
final readonly class BaselineUpdater
{
    public function __construct(
        private ChannelDeclarationRegistryInterface $declarations,
        private ClockInterface $clock,
    ) {}

    /**
     * @param list<Violation> $measured the run's measured set (ADR 0017)
     * @param RunScope $scope the paths this run analysed; recorded only when it covers
     *                        what the file already records — see {@see scopeToRecord()}
     */
    public function update(Baseline $baseline, array $measured, RunScope $scope): BaselineUpdateResult
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
            scope: self::scopeToRecord($baseline, $scope),
            entries: $entries,
            inertEntries: $baseline->inertEntries,
            sourceContentHash: $baseline->sourceContentHash,
        );

        return new BaselineUpdateResult($updated, $outcomes);
    }

    /**
     * The `scope` the updated file records: the run's own only when it covers
     * what the file already records, and otherwise the recorded one, unchanged.
     *
     * **Why a narrower run must not overwrite it.** The scope guard (ADR 0017) is
     * a precondition of this command, overridable with `--force` — and an
     * overwrite would make one `--force` permanent. A user updating from
     * `src/Legacy` once would leave the file claiming a narrow run produced
     * it, after which every subsequent narrow run covers the recorded scope
     * and the guard never fires again: the single override silently becomes a
     * standing rule. Keeping the recorded scope means `--force` does exactly
     * what it says — it lets *this* invocation write — while the file goes on
     * remembering the breadth its entries were actually captured over.
     *
     * A run that *does* cover the recorded scope is recorded as-is: it is at
     * least as wide, so the entries it wrote are backed by at least as much
     * measurement, and widening the file's own claim is the honest direction.
     *
     * @return list<string>
     */
    private static function scopeToRecord(Baseline $baseline, RunScope $scope): array
    {
        return $scope->covers($baseline->scope) ? $scope->paths() : $baseline->scope;
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

        // Applicability, before anything about the measured group: a channel
        // that reports a configuration error is never re-recorded, so
        // `update` cannot turn a misconfigured run into a wider acceptance.
        if ($declaration->isConfigurationError()) {
            return [
                $entry,
                BaselineEntryUpdateOutcome::refused($entry->identity, BaselineUpdateRefusalReason::ConfigurationErrorChannel),
            ];
        }

        // The channel's own shape moved to the producer (ADR 0031);
        // `$declaration->direction` is null exactly when the producer
        // declared `occurrence`, since registry assembly refuses any other
        // combination. Comparing nullability against the entry's
        // self-derived shape is the same check as before.
        $declarationIsOccurrence = $declaration->direction === null;

        if ($declarationIsOccurrence !== ($entry->shape() === ChannelShape::Occurrence)) {
            return [$entry, BaselineEntryUpdateOutcome::refused($entry->identity, BaselineUpdateRefusalReason::ShapeMismatch)];
        }

        return $declarationIsOccurrence
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
            return [$entry, BaselineEntryUpdateOutcome::refused($entry->identity, self::worsenedReason($entry))];
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
            // magnitudes is non-null, and reconcile() already matched that
            // against $declaration->direction being non-null before calling
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
            return [$entry, BaselineEntryUpdateOutcome::refused($entry->identity, self::worsenedReason($entry))];
        }

        $written = new BaselineEntry($entry->identity, $current, \count($current), $entry->mode);

        return [$written, BaselineEntryUpdateOutcome::updated($entry->identity)];
    }

    /**
     * Which refusal a declined comparison is, on this entry.
     *
     * The comparison itself is the same one on every entry — a suppressed
     * entry is *not* exempt from it, or `update` would become a way to widen
     * an acceptance (ADR 0017). What differs is what the refusal means to a user:
     * on a `mode: suppress` entry the ceiling never compares these numbers at
     * `check` time, so nothing observable worsened and the word "worsened"
     * would send the user looking for a red build that is not there.
     * Behaviour is identical in both branches; only the name is not.
     */
    private static function worsenedReason(BaselineEntry $entry): BaselineUpdateRefusalReason
    {
        return $entry->mode === BaselineEntryMode::Suppress
            ? BaselineUpdateRefusalReason::WorsenedUnderSuppression
            : BaselineUpdateRefusalReason::Worsened;
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
