<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\Ceiling;

use Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageObservation;
use Qualimetrix\Analysis\Finding\Contract\ValueReach;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;
use Qualimetrix\Analysis\Policy\Baseline\RunScope;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** One proof that a recorded entry's population was measured again. */
final readonly class EntryComparability
{
    private function __construct(public ?IncomparabilityReason $reason) {}

    public static function comparable(): self
    {
        return new self(null);
    }

    public static function refused(IncomparabilityReason $reason): self
    {
        return new self($reason);
    }

    public function canCompare(): bool
    {
        return $this->reason === null;
    }

    public static function judge(Region $region, Baseline $baseline, RunCoverage $run): self
    {
        if (!$run->analysis->isComplete()) {
            return self::refused(IncomparabilityReason::AnalysisIncomplete);
        }
        if ($region->kind === 'empty') {
            return self::comparable();
        }

        $recordedScope = RunScope::fromRecorded($baseline->scope);
        $delta = new ExclusionDelta($baseline->exclusions, $run->exclusions);

        return $region->kind === 'file'
            ? self::judgeFile($region, $run, $recordedScope, $delta)
            : self::judgeAggregate($region, $run, $recordedScope, $delta);
    }

    private static function judgeFile(Region $region, RunCoverage $run, RunScope $recordedScope, ExclusionDelta $delta): self
    {
        $file = $region->file;
        if ($file === null) {
            return self::refused(IncomparabilityReason::MetadataUnknown);
        }
        $presence = $run->hasFile($file);
        if ($presence === ProjectEntryPresence::Unknown) {
            return self::refused(IncomparabilityReason::MetadataUnknown);
        }
        if ($presence === ProjectEntryPresence::Absent) {
            if ($delta->differsAt($file)) {
                return self::refused(IncomparabilityReason::ExclusionsDiffer);
            }
            if ($delta->currentlyExcluded($file)) {
                return self::refused(IncomparabilityReason::OutsideCoverage);
            }
            $rootPresence = RecordedRootPresence::forFile($file, $recordedScope, $run);
            if ($rootPresence !== ProjectEntryPresence::Present) {
                return self::refused($rootPresence === ProjectEntryPresence::Unknown
                    ? IncomparabilityReason::MetadataUnknown
                    : IncomparabilityReason::OutsideCoverage);
            }

            return $run->subjectCoverage->covers(ValueReach::Members, SymbolLevel::File, SubjectCoverageObservation::verifiedAbsentFile($file))
                ? self::comparable()
                : self::refused(IncomparabilityReason::OutsideCoverage);
        }
        return self::judgePresentFile($file, $run, $recordedScope, $delta);
    }

    private static function judgePresentFile(RelativePath $file, RunCoverage $run, RunScope $recordedScope, ExclusionDelta $delta): self
    {
        $samePaths = $run->scope->paths() === $recordedScope->paths();
        $current = $samePaths || $run->scope->coversPath($file->value());
        $recorded = $samePaths || $recordedScope->coversPath($file->value());
        if (!$current || !$recorded) {
            return self::refused(IncomparabilityReason::OutsideCoverage);
        }
        if (!$run->subjectCoverage->covers(ValueReach::Members, SymbolLevel::File, SubjectCoverageObservation::analyzedFile($file))) {
            return self::refused(IncomparabilityReason::OutsideCoverage);
        }
        if ($delta->differsAt($file)) {
            return self::refused(IncomparabilityReason::ExclusionsDiffer);
        }
        if ($delta->currentlyExcluded($file)) {
            return self::refused(IncomparabilityReason::OutsideCoverage);
        }

        return self::comparable();
    }

    private static function judgeAggregate(Region $region, RunCoverage $run, RunScope $recordedScope, ExclusionDelta $delta): self
    {
        if ($run->scope->paths() === $recordedScope->paths() && $delta->equalDefinitions()) {
            return self::comparable();
        }

        $snapshot = $run->snapshot();
        if (!$snapshot->complete()) {
            return self::refused(IncomparabilityReason::MetadataUnknown);
        }
        if ($delta->generatedPolicyChanged()) {
            return self::refused(IncomparabilityReason::MetadataUnknown);
        }

        foreach ($snapshot->phpFiles as $file) {
            if (!$region->contains($file)) {
                continue;
            }
            $reason = self::populationDifference($file, $run, $recordedScope, $delta);
            if ($reason !== null) {
                return self::refused($reason);
            }
        }

        return self::snapshotCoversRegion($region, $run)
            ? self::comparable()
            : self::refused(IncomparabilityReason::MetadataUnknown);
    }

    private static function snapshotCoversRegion(Region $region, RunCoverage $run): bool
    {
        if ($region->kind !== 'namespace' || $region->roots === []) {
            return false;
        }
        $snapshotScope = RunScope::record(array_column($run->universe->denominator, 'path'), $run->universe->projectRoot);

        return array_all($region->roots, static fn(RelativePath $root): bool => $snapshotScope->coversPath($root->value()));
    }

    private static function populationDifference(RelativePath $file, RunCoverage $run, RunScope $recordedScope, ExclusionDelta $delta): ?IncomparabilityReason
    {
        $current = $run->scope->coversPath($file->value());
        $recorded = $recordedScope->coversPath($file->value());
        if ($current !== $recorded) {
            return $current ? IncomparabilityReason::PathsDiffer : IncomparabilityReason::OutsideCoverage;
        }
        if ($current && $delta->differsAt($file)) {
            return IncomparabilityReason::ExclusionsDiffer;
        }

        return null;
    }
}
