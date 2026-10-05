<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\Ceiling;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\AcceptedLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStageInterface;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStageResult;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryMode;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\Contract\CeilingOutcome;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;
use Qualimetrix\Analysis\Policy\Baseline\GroupAcceptance;
use Qualimetrix\Analysis\Policy\Baseline\InertBaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\InertEntryReason;
use Qualimetrix\Analysis\Policy\Baseline\RunCoverageGap;
use Qualimetrix\Core\Symbol\MetricSubject;

/** Judges present groups and absent entries against one measured run. */
final readonly class BaselineCeilingStage implements FindingFilterStageInterface
{
    /** @param array<string, RunCoverageGap> $ruleGaps Keyed by identity. */
    public function __construct(
        private Baseline $baseline,
        private ChannelDeclarationRegistryInterface $declarations,
        private RunCoverage $coverage,
        private array $ruleGaps,
    ) {}

    public function stage(): FindingFilterStage
    {
        return FindingFilterStage::Baseline;
    }

    public function apply(array $findings): FindingFilterStageResult
    {
        return $this->judgeAll($findings)->result;
    }

    /** @param list<Finding> $findings */
    public function judgeAll(array $findings): CeilingOutcome
    {
        $groups = self::groupByIdentity($findings);
        $verdicts = $statuses = $reasons = [];
        foreach ($groups as $key => $group) {
            $verdict = $this->judgePresent($group['identity'], $group['findings']);
            $verdicts[$key] = $verdict;
            $statuses[$key] = $verdict->status();
            if ($verdict->uncomparedReason !== null) {
                $reasons[$key] = $verdict->uncomparedReason->value;
            }
        }

        $kept = $removed = [];
        foreach ($findings as $finding) {
            $verdict = $verdicts[BaselineIdentity::forFinding($finding)->key()];
            if ($verdict->suppresses()) {
                $removed[] = $finding;

                continue;
            }
            $kept[] = match (true) {
                $verdict->breachedLevel !== null => $finding->reportedAsBreach($verdict->breachedLevel),
                $verdict->uncomparedLevel !== null && $verdict->uncomparedReason !== null
                    => $finding->reportedUncompared($verdict->uncomparedLevel, $verdict->uncomparedReason->value),
                default => $finding,
            };
        }

        $stale = $unmeasured = $outside = $notCompared = [];
        foreach ($this->baseline->entries as $entry) {
            $key = $entry->identity->key();
            if (isset($groups[$key])) {
                continue;
            }
            $absence = $this->classifyAbsent($entry);
            $statuses[$key] = $absence->status;
            if ($absence->reason !== null) {
                $reasons[$key] = $absence->reason->value;
            }
            switch ($absence->status) {
                case 'stale': $stale[] = $entry;
                    break;
                case 'unmeasured': $unmeasured[] = $entry;
                    break;
                case 'outside-coverage': $outside[] = $entry;
                    break;
                default: $notCompared[] = $entry;
            }
        }

        return new CeilingOutcome(
            result: new FindingFilterStageResult(FindingFilterStage::Baseline, $kept, $removed),
            staleEntries: $stale,
            inertEntries: [...$this->baseline->inertEntries, ...$this->configurationErrorEntries()],
            unmeasuredEntries: $unmeasured,
            outsideCoverageEntries: $outside,
            notComparedEntries: $notCompared,
            statuses: $statuses,
            reasons: $reasons,
        );
    }

    /** @return list<string> */
    public function baselineScope(): array
    {
        return $this->baseline->scope;
    }

    /** @param non-empty-list<Finding> $group */
    private function judgePresent(BaselineIdentity $identity, array $group): GroupCeilingVerdict
    {
        $entry = $this->baseline->findByIdentity($identity);
        if ($entry === null) {
            return GroupCeilingVerdict::reported();
        }
        if (!$this->coverage->analysis->isComplete()) {
            return GroupCeilingVerdict::uncompared(self::levelOf($entry), IncomparabilityReason::AnalysisIncomplete);
        }
        if (isset($this->ruleGaps[$identity->key()])) {
            return GroupCeilingVerdict::uncompared(self::levelOf($entry), IncomparabilityReason::ProducerNotMeasured);
        }

        $declaration = $this->declarations->declarationFor($identity->channel);
        if ($declaration === null || $declaration->isConfigurationError()) {
            return GroupCeilingVerdict::reported();
        }
        $level = MetricSubject::levelOfCanonical($identity->subjectKey);
        if (!\in_array($level, $declaration->levels, true)) {
            return GroupCeilingVerdict::uncompared(self::levelOf($entry), IncomparabilityReason::ProducerNotMeasured);
        }
        $occurrence = $declaration->direction === null;
        if (($entry->magnitudes === null) !== $occurrence) {
            return GroupCeilingVerdict::reported();
        }
        $region = SubjectRegion::forIdentity(
            $identity,
            $this->declarations->reachAt($identity->channel, $level),
            $this->coverage->psr4Roots,
            $group,
        );
        $comparison = EntryComparability::judge($region, $this->baseline, $this->coverage);
        if (!$comparison->canCompare()) {
            return GroupCeilingVerdict::uncompared(self::levelOf($entry), $comparison->reason ?? IncomparabilityReason::MetadataUnknown);
        }
        if ($entry->mode === BaselineEntryMode::Suppress) {
            return GroupCeilingVerdict::accepted();
        }

        $measurement = GroupMeasurement::fromFindings($group, $occurrence);
        if (!$measurement->complete()) {
            return GroupCeilingVerdict::uncompared(self::levelOf($entry), IncomparabilityReason::MagnitudeUnavailable);
        }
        $accepted = $occurrence
            ? GroupAcceptance::countWithin($measurement->count, $entry->count)
            : GroupAcceptance::magnitudesWithin(
                $measurement->magnitudes ?? [],
                $entry->magnitudes ?? [],
                $declaration->direction ?? throw new LogicException('Magnitude channel requires a direction'),
            );

        return $accepted ? GroupCeilingVerdict::accepted() : GroupCeilingVerdict::breached(self::levelOf($entry));
    }

    private function classifyAbsent(BaselineEntry $entry): Absence
    {
        if (!$this->coverage->analysis->isComplete()) {
            return Absence::unmeasured(IncomparabilityReason::AnalysisIncomplete);
        }
        if (isset($this->ruleGaps[$entry->identity->key()])) {
            return Absence::unmeasured(IncomparabilityReason::ProducerNotMeasured);
        }
        $declaration = $this->declarations->declarationFor($entry->identity->channel);
        if ($declaration === null || $declaration->isConfigurationError()) {
            return Absence::notCompared(IncomparabilityReason::ProducerNotMeasured);
        }
        $level = MetricSubject::levelOfCanonical($entry->identity->subjectKey);
        if (!\in_array($level, $declaration->levels, true)) {
            return Absence::notCompared(IncomparabilityReason::ProducerNotMeasured);
        }
        $region = SubjectRegion::forIdentity(
            $entry->identity,
            $this->declarations->reachAt($entry->identity->channel, $level),
            $this->coverage->psr4Roots,
        );
        $comparison = EntryComparability::judge($region, $this->baseline, $this->coverage);
        if ($comparison->canCompare()) {
            return Absence::stale();
        }
        return $comparison->reason === IncomparabilityReason::OutsideCoverage
            ? Absence::outsideCoverage()
            : Absence::notCompared($comparison->reason ?? IncomparabilityReason::MetadataUnknown);
    }

    /** @return list<InertBaselineEntry> */
    private function configurationErrorEntries(): array
    {
        $inert = [];
        foreach ($this->baseline->entries as $entry) {
            $declaration = $this->declarations->declarationFor($entry->identity->channel);
            if ($declaration?->isConfigurationError() !== true) {
                continue;
            }
            $inert[] = InertBaselineEntry::forIdentity(
                $entry->identity,
                InertEntryReason::ConfigurationErrorChannel,
                \sprintf('the channel "%s" reports a configuration error, which cannot be accepted as debt', $entry->identity->channel->code),
                raw: null,
            );
        }

        return $inert;
    }

    private static function levelOf(BaselineEntry $entry): AcceptedLevel
    {
        return new AcceptedLevel($entry->magnitudes, $entry->count);
    }

    /**
     * @param list<Finding> $findings
     *
     * @return array<string, array{identity: BaselineIdentity, findings: non-empty-list<Finding>}>
     */
    private static function groupByIdentity(array $findings): array
    {
        $groups = [];
        foreach ($findings as $finding) {
            $identity = BaselineIdentity::forFinding($finding);
            $groups[$identity->key()] ??= ['identity' => $identity, 'findings' => []];
            $groups[$identity->key()]['findings'][] = $finding;
        }

        return $groups;
    }
}
