<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\Ceiling;

use Qualimetrix\Analysis\Finding\Contract\AcceptedLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryMode;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;
use Qualimetrix\Analysis\Policy\Baseline\GroupAcceptance;
use Qualimetrix\Analysis\Policy\Baseline\RunCoverageGap;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\MetricSubject;

/** Judges a baseline entry against present findings. */
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
        return $this->comparePresent($entry, $identity, $group, $declaration->direction);
    }

    /** @param non-empty-list<Finding> $group */
    private function comparePresent(BaselineEntry $entry, BaselineIdentity $identity, array $group, ?WorseDirection $direction): GroupCeilingVerdict
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

        return self::judgeMeasuredGroup($entry, $group, $direction);
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

    private static function levelOf(BaselineEntry $entry): AcceptedLevel
    {
        return new AcceptedLevel($entry->magnitudes, $entry->count);
    }
}
