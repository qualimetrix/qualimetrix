<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\Ceiling;

use Qualimetrix\Analysis\Finding\Contract\AcceptedLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageObservation;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryMode;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;
use Qualimetrix\Analysis\Policy\Baseline\GroupAcceptance;
use Qualimetrix\Analysis\Policy\Baseline\RunCoverageGap;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\MetricSubject;

/** Judges a baseline entry against present findings or proven absence. */
final readonly class EntryJudgement
{
    /** @param array<string, RunCoverageGap> $ruleGaps Keyed by identity. */
    public function __construct(
        private Baseline $baseline,
        private ChannelDeclarationRegistryInterface $declarations,
        private RunCoverage $coverage,
        private array $ruleGaps,
    ) {}

    /** @param non-empty-list<Finding> $group */
    public function judgePresent(BaselineEntry $entry, BaselineIdentity $identity, array $group): GroupCeilingVerdict
    {
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
        return $this->comparePresent($entry, $identity, $group, $declaration);
    }

    /** @param non-empty-list<Finding> $group */
    private function comparePresent(BaselineEntry $entry, BaselineIdentity $identity, array $group, ChannelDeclaration $declaration): GroupCeilingVerdict
    {
        $region = SubjectRegion::forIdentity(
            $identity,
            $this->declarations->reachAt($identity->channel, MetricSubject::levelOfCanonical($identity->subjectKey)),
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

        return self::judgeMeasuredGroup($entry, $group, $declaration->direction);
    }

    /** @param non-empty-list<Finding> $group */
    private static function judgeMeasuredGroup(BaselineEntry $entry, array $group, ?WorseDirection $direction): GroupCeilingVerdict
    {
        $measurement = GroupMeasurement::fromFindings($group, $direction === null ? ChannelShape::Occurrence : ChannelShape::Magnitude);
        if (!$measurement->complete()) {
            return GroupCeilingVerdict::uncompared(self::levelOf($entry), IncomparabilityReason::MagnitudeUnavailable);
        }
        $accepted = $direction === null
            ? GroupAcceptance::countWithin($measurement->count, $entry->count)
            : GroupAcceptance::magnitudesWithin(
                $measurement->magnitudes ?? [],
                $entry->magnitudes ?? [],
                $direction,
            );

        return $accepted ? GroupCeilingVerdict::accepted() : GroupCeilingVerdict::breached(self::levelOf($entry));
    }

    public function classifyAbsent(BaselineEntry $entry): Absence
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
        $reach = $this->declarations->reachAt($entry->identity->channel, $level);
        $subjectFile = SubjectRegion::subjectFile($entry->identity);
        $presence = $subjectFile === null ? null : $this->coverage->hasFile($subjectFile);
        if ($presence === \Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence::Unknown) {
            return Absence::notCompared(IncomparabilityReason::MetadataUnknown);
        }
        if ($presence === \Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence::Absent) {
            $comparison = EntryComparability::judge(Region::file($subjectFile), $this->baseline, $this->coverage);
            if ($comparison->canCompare() && !$this->coverage->subjectCoverage->covers($reach, $level, SubjectCoverageObservation::verifiedAbsentFile($subjectFile))) {
                $comparison = EntryComparability::refused(IncomparabilityReason::OutsideCoverage);
            }
        } else {
            $region = SubjectRegion::forIdentity(
                $entry->identity,
                $reach,
                $this->coverage->psr4Roots,
            );
            $comparison = EntryComparability::judge($region, $this->baseline, $this->coverage);
            if ($comparison->canCompare() && $region->kind !== 'file' && !$this->coverage->subjectCoverage->covers($reach, $level, SubjectCoverageObservation::nonlocalRegion())) {
                $comparison = EntryComparability::refused(IncomparabilityReason::OutsideCoverage);
            }
        }
        if ($comparison->canCompare()) {
            return Absence::stale();
        }
        return $comparison->reason === IncomparabilityReason::OutsideCoverage
            ? Absence::outsideCoverage()
            : Absence::notCompared($comparison->reason ?? IncomparabilityReason::MetadataUnknown);
    }

    private static function levelOf(BaselineEntry $entry): AcceptedLevel
    {
        return new AcceptedLevel($entry->magnitudes, $entry->count);
    }
}
