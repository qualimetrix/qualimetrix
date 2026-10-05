<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\BaselineCeilingStage;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\EntryComparability;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\GroupCapture;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\GroupMeasurement;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\SubjectRegion;
use Qualimetrix\Analysis\Policy\Baseline\Contract\CeilingOutcome;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RecordedExclusions;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Time\ClockInterface;

/**
 * `baseline:update`: ordinary tightening plus explicit acceptance and
 * exclusion recapture against a fresh measured run (ADR 0017).
 *
 * `acceptNew` preserves existing acceptance and captures only unoccupied,
 * comparable identities of the named channels. `recordExclusions` recaptures
 * an entry only when exclusions alone prevented comparison; its mode stays
 * with the entry. The rules below govern ordinary `update`.
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
 *   test {@see \Qualimetrix\Analysis\Policy\Baseline\Ceiling\BaselineCeilingStage} applies at
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
     * @param list<Finding> $measured
     * @param array<string, RunCoverageGap> $ruleGaps
     */
    public function update(Baseline $baseline, array $measured, RunCoverage $coverage, array $ruleGaps): BaselineUpdateResult
    {
        $groups = self::groupByIdentity($measured);
        $judgement = (new BaselineCeilingStage($baseline, $this->declarations, $coverage, $ruleGaps))->judgeAll($measured);
        $entries = $outcomes = [];
        foreach ($baseline->entries as $entry) {
            [$written, $outcome] = $this->reconcileJudged($entry, $groups[$entry->identity->key()] ?? null, $judgement);
            $entries[] = $written;
            $outcomes[] = $outcome;
        }

        return $this->result($baseline, $entries, $outcomes, $baseline->exclusions);
    }

    /**
     * @param list<Finding> $measured
     * @param list<FindingChannel> $channels
     */
    public function acceptNew(Baseline $baseline, array $measured, array $channels, RunCoverage $coverage, RunRuleCoverage $publication): BaselineUpdateResult
    {
        $occupied = [];
        foreach ($baseline->entries as $entry) {
            $occupied[$entry->identity->key()] = 'existing-entry';
        }
        foreach ($baseline->inertEntries as $entry) {
            if ($entry->identity !== null) {
                $occupied[$entry->identity->key()] = 'inert-holds-identity';
            }
        }
        $selected = [];
        $notes = [];
        foreach ($channels as $channel) {
            $declaration = $this->declarations->declarationFor($channel);
            $published = $declaration !== null && array_any(
                $declaration->levels,
                static fn(SymbolLevel $level): bool => $publication->publishes($channel, $level),
            );
            $selected[$channel->code] = $published;
            $notes[$channel->code] = $published ? 'no-finding' : 'not-measured';
        }

        $entries = $baseline->entries;
        $outcomes = [];
        $capture = new GroupCapture($this->declarations);
        foreach (self::groupByIdentity($measured) as $key => $group) {
            $identity = BaselineIdentity::forFinding($group[0]);
            if (!isset($selected[$identity->channel->code]) || !$selected[$identity->channel->code]) {
                continue;
            }
            $notes[$identity->channel->code] = 'no-comparable-new-identities';
            if (isset($occupied[$key])) {
                $outcomes[] = BaselineEntryUpdateOutcome::skipped($identity, $occupied[$key]);
                continue;
            }
            $level = MetricSubject::levelOfCanonical($identity->subjectKey);
            if (!\in_array($level, $this->declarations->declarationFor($identity->channel)->levels ?? [], true)
                || !$publication->publishes($identity->channel, $level)) {
                $outcomes[] = BaselineEntryUpdateOutcome::skipped($identity, 'not-measured');
                continue;
            }
            $region = SubjectRegion::forIdentity($identity, $this->declarations->reachAt($identity->channel, $level), $coverage->psr4Roots, $group);
            $comparison = EntryComparability::judge($region, $baseline, $coverage);
            if (!$comparison->canCompare()) {
                $outcomes[] = BaselineEntryUpdateOutcome::skipped($identity, $comparison->reason?->value);
                continue;
            }
            $captured = $capture->capture($identity, $group);
            if (!$captured instanceof BaselineEntry) {
                $outcomes[] = BaselineEntryUpdateOutcome::skipped($identity, $captured->value);
                continue;
            }
            $entries[] = $captured;
            $outcomes[] = BaselineEntryUpdateOutcome::accepted($identity);
            unset($notes[$identity->channel->code]);
        }

        foreach ($outcomes as $outcome) {
            if ($outcome->disposition === BaselineUpdateDisposition::Accepted) {
                unset($notes[$outcome->identity->channel->code]);
            }
        }
        return $this->result($baseline, $entries, $outcomes, $baseline->exclusions, $notes);
    }

    /**
     * @param list<Finding> $measured
     * @param array<string, RunCoverageGap> $ruleGaps
     */
    public function recordExclusions(Baseline $baseline, array $measured, RunCoverage $coverage, array $ruleGaps): BaselineUpdateResult
    {
        if ($coverage->scope->paths() !== $baseline->scope) {
            return new BaselineUpdateResult($baseline, [], false, writeRefusal: BaselineUpdateRefusalReason::RecordedPathsDiffer);
        }
        $groups = self::groupByIdentity($measured);
        $judgement = (new BaselineCeilingStage($baseline, $this->declarations, $coverage, $ruleGaps))->judgeAll($measured);
        $entries = $outcomes = [];
        $refusal = null;
        $capture = new GroupCapture($this->declarations);
        foreach ($baseline->entries as $entry) {
            $reason = $judgement->reasonFor($entry->identity);
            $group = $groups[$entry->identity->key()] ?? null;
            if ($reason === 'metadata-unknown' || $reason === 'analysis-incomplete'
                || (!$baseline->exclusions->equals($coverage->exclusions) && $reason === 'producer-not-measured')) {
                $refusal = BaselineUpdateRefusalReason::ComparisonMetadataUnknown;
                $entries[] = $entry;
                $outcomes[] = BaselineEntryUpdateOutcome::notCompared($entry->identity, $reason);
                continue;
            }
            if ($reason === 'exclusions-differ') {
                $captured = $group === null ? null : $capture->capture($entry->identity, $group);
                if (!$captured instanceof BaselineEntry) {
                    $refusal = BaselineUpdateRefusalReason::RequiredGroupUnavailable;
                    $entries[] = $entry;
                    $outcomes[] = BaselineEntryUpdateOutcome::refused($entry->identity, $refusal);
                    continue;
                }
                $written = new BaselineEntry($captured->identity, $captured->magnitudes, $captured->count, $entry->mode);
                $entries[] = $written;
                $outcomes[] = BaselineEntryUpdateOutcome::reRecorded($entry, $written);
                continue;
            }
            [$written, $outcome] = $this->reconcileJudged($entry, $group, $judgement);
            $entries[] = $written;
            $outcomes[] = $outcome;
        }
        if ($refusal !== null) {
            return new BaselineUpdateResult($baseline, $outcomes, false, writeRefusal: $refusal);
        }
        return $this->result($baseline, $entries, $outcomes, $coverage->exclusions);
    }

    /**
     * @param ?non-empty-list<Finding> $group
     *
     * @return array{BaselineEntry, BaselineEntryUpdateOutcome}
     */
    private function reconcileJudged(BaselineEntry $entry, ?array $group, CeilingOutcome $judgement): array
    {
        $status = $judgement->statusFor($entry->identity);
        if ($status === 'not-compared' || $status === 'unmeasured' || $status === 'outside-coverage') {
            return [$entry, BaselineEntryUpdateOutcome::notCompared($entry->identity, $judgement->reasonFor($entry->identity) ?? 'metadata-unknown')];
        }
        if ($group === null) {
            return [$entry, BaselineEntryUpdateOutcome::skipped($entry->identity)];
        }
        [$written, $outcome] = $this->reconcile($entry, $group);
        if ($outcome->disposition === BaselineUpdateDisposition::Updated && $written->toArray() === $entry->toArray()) {
            $outcome = BaselineEntryUpdateOutcome::unchanged($entry->identity);
        }
        return [$written, $outcome];
    }

    /**
     * @param list<BaselineEntry> $entries
     * @param list<BaselineEntryUpdateOutcome> $outcomes
     * @param array<string, string> $notes
     */
    private function result(Baseline $baseline, array $entries, array $outcomes, RecordedExclusions $exclusions, array $notes = []): BaselineUpdateResult
    {
        $changed = !$baseline->exclusions->equals($exclusions)
            || array_map(static fn(BaselineEntry $entry): array => $entry->toArray(), $entries)
                !== array_map(static fn(BaselineEntry $entry): array => $entry->toArray(), $baseline->entries);
        $updated = new Baseline(
            generated: $changed ? $this->clock->now() : $baseline->generated,
            scope: $baseline->scope,
            entries: $entries,
            exclusions: $exclusions,
            inertEntries: $baseline->inertEntries,
            sourceContentHash: $baseline->sourceContentHash,
        );
        return new BaselineUpdateResult($updated, $outcomes, $changed, $notes);
    }

    /**
     * Decides one entry, in the order applicability requires: whether the
     * entry can be compared at all is settled before anything about the
     * measured group is read, mirroring the ceiling stage's own ordering.
     *
     * @param non-empty-list<Finding> $group every measured finding sharing the entry's identity
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
     * @param non-empty-list<Finding> $group
     *
     * @return array{BaselineEntry, BaselineEntryUpdateOutcome}
     */
    private function reconcileOccurrence(BaselineEntry $entry, array $group): array
    {
        $currentCount = GroupMeasurement::fromFindings($group, true)->count;

        if (!GroupAcceptance::countWithin($currentCount, $entry->count)) {
            return [$entry, BaselineEntryUpdateOutcome::refused($entry->identity, self::worsenedReason($entry))];
        }

        $written = new BaselineEntry($entry->identity, null, $currentCount, $entry->mode);

        return [$written, BaselineEntryUpdateOutcome::updated($entry->identity)];
    }

    /**
     * @param non-empty-list<Finding> $group
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

        $current = GroupMeasurement::fromFindings($group, false)->magnitudes;

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
     * @param list<Finding> $findings
     *
     * @return array<string, non-empty-list<Finding>> identity key => its group
     */
    private static function groupByIdentity(array $findings): array
    {
        $groups = [];

        foreach ($findings as $finding) {
            $groups[BaselineIdentity::forFinding($finding)->key()][] = $finding;
        }

        return $groups;
    }
}
