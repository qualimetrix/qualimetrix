<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\Ceiling;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageObservation;
use Qualimetrix\Analysis\Finding\Contract\ValueReach;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;
use Qualimetrix\Analysis\Policy\Baseline\RunCoverageGap;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** Evidence required before a baseline identity can be called stale. */
final readonly class EntryAbsenceProof
{
    /** @param array<string, RunCoverageGap> $ruleGaps */
    public function __construct(
        private Baseline $baseline,
        private ChannelDeclarationRegistryInterface $declarations,
        private RunCoverage $coverage,
        private array $ruleGaps,
    ) {}

    public function classify(BaselineEntry $entry): Absence
    {
        $refusal = $this->preconditionRefusal($entry);
        if ($refusal !== null) {
            return $refusal;
        }

        $level = MetricSubject::levelOfCanonical($entry->identity->subjectKey);
        $reach = $this->declarations->reachAt($entry->identity->channel, $level);

        return self::absenceFrom($this->compareSubject($entry, $reach, $level));
    }

    private function preconditionRefusal(BaselineEntry $entry): ?Absence
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

        return null;
    }

    private function compareSubject(BaselineEntry $entry, ValueReach $reach, SymbolLevel $level): EntryComparability
    {
        $subjectFile = SubjectRegion::subjectFile($entry->identity);
        $presence = $subjectFile === null ? null : $this->coverage->hasFile($subjectFile);
        if ($presence === ProjectEntryPresence::Unknown) {
            return EntryComparability::refused(IncomparabilityReason::MetadataUnknown);
        }

        return $presence === ProjectEntryPresence::Absent
            ? $this->compareAbsentFile($subjectFile, $reach, $level)
            : $this->compareRegion($entry, $reach, $level);
    }

    private static function absenceFrom(EntryComparability $comparison): Absence
    {
        return match (true) {
            $comparison->canCompare() => Absence::stale(),
            $comparison->reason === IncomparabilityReason::OutsideCoverage => Absence::outsideCoverage(),
            default => Absence::notCompared($comparison->reason ?? IncomparabilityReason::MetadataUnknown),
        };
    }

    private function compareAbsentFile(RelativePath $file, ValueReach $reach, SymbolLevel $level): EntryComparability
    {
        $comparison = EntryComparability::judge(Region::file($file), $this->baseline, $this->coverage);
        if ($comparison->canCompare() && !$this->coverage->subjectCoverage->covers($reach, $level, SubjectCoverageObservation::verifiedAbsentFile($file))) {
            return EntryComparability::refused(IncomparabilityReason::OutsideCoverage);
        }

        return $comparison;
    }

    private function compareRegion(BaselineEntry $entry, ValueReach $reach, SymbolLevel $level): EntryComparability
    {
        $region = SubjectRegion::forIdentity($entry->identity, $reach, $this->coverage->psr4Roots);
        $comparison = EntryComparability::judge($region, $this->baseline, $this->coverage);
        if ($comparison->canCompare() && $region->kind !== 'file' && !$this->coverage->subjectCoverage->covers($reach, $level, SubjectCoverageObservation::nonlocalRegion())) {
            return EntryComparability::refused(IncomparabilityReason::OutsideCoverage);
        }

        return $comparison;
    }
}
