<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\BaselineCeilingStage;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\GroupCapture;
use Qualimetrix\Analysis\Policy\Baseline\Contract\CeilingOutcome;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RecordedExclusions;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;
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
        $tightening = new BaselineEntryTightening($this->declarations);
        foreach ($baseline->entries as $entry) {
            [$written, $outcome] = $tightening->tighten($entry, $groups[$entry->identity->key()] ?? null, $judgement);
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
        [$entries, $outcomes, $notes] = (new NewIdentityAcceptance($this->declarations))->accept($baseline, $measured, $channels, $coverage, $publication);

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
        $tightening = new BaselineEntryTightening($this->declarations);
        $refusal = null;
        $capture = new GroupCapture($this->declarations);
        foreach ($baseline->entries as $entry) {
            [$written, $outcome, $entryRefusal] = $this->recordEntry($entry, $groups[$entry->identity->key()] ?? null, $baseline, $coverage->exclusions, $judgement, $tightening, $capture);
            $entries[] = $written;
            $outcomes[] = $outcome;
            $refusal = $entryRefusal ?? $refusal;
        }
        if ($refusal !== null) {
            return new BaselineUpdateResult($baseline, $outcomes, false, writeRefusal: $refusal);
        }
        return $this->result($baseline, $entries, $outcomes, $coverage->exclusions);
    }

    /**
     * @param ?non-empty-list<Finding> $group
     *
     * @return array{BaselineEntry, BaselineEntryUpdateOutcome, ?BaselineUpdateRefusalReason}
     */
    private function recordEntry(
        BaselineEntry $entry,
        ?array $group,
        Baseline $baseline,
        RecordedExclusions $exclusions,
        CeilingOutcome $judgement,
        BaselineEntryTightening $tightening,
        GroupCapture $capture,
    ): array {
        $reason = $judgement->reasonFor($entry->identity);
        if ($reason === 'metadata-unknown' || $reason === 'analysis-incomplete'
            || (!$baseline->exclusions->equals($exclusions) && $reason === 'producer-not-measured')) {
            return [$entry, BaselineEntryUpdateOutcome::notCompared($entry->identity, $reason), BaselineUpdateRefusalReason::ComparisonMetadataUnknown];
        }
        if ($reason === 'exclusions-differ') {
            return self::recaptureEntry($entry, $group, $capture);
        }
        [$written, $outcome] = $tightening->tighten($entry, $group, $judgement);

        return [$written, $outcome, null];
    }

    /**
     * @param ?non-empty-list<Finding> $group
     *
     * @return array{BaselineEntry, BaselineEntryUpdateOutcome, ?BaselineUpdateRefusalReason}
     */
    private static function recaptureEntry(BaselineEntry $entry, ?array $group, GroupCapture $capture): array
    {
        $captured = $group === null ? null : $capture->capture($entry->identity, $group);
        if (!$captured instanceof BaselineEntry) {
            $refusal = BaselineUpdateRefusalReason::RequiredGroupUnavailable;

            return [$entry, BaselineEntryUpdateOutcome::refused($entry->identity, $refusal), $refusal];
        }
        $written = new BaselineEntry($captured->identity, $captured->magnitudes, $captured->count, $entry->mode);

        return [$written, BaselineEntryUpdateOutcome::reRecorded($entry, $written), null];
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
