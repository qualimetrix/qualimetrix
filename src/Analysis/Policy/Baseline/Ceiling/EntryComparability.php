<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\Ceiling;

use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;
use Qualimetrix\Analysis\Policy\Baseline\RunScope;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence;

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

        if ($region->kind === 'file') {
            $file = $region->file;
            if ($file === null) {
                return self::refused(IncomparabilityReason::MetadataUnknown);
            }
            $presence = $run->hasFile($file);
            if ($presence === ProjectEntryPresence::Unknown) {
                return self::refused(IncomparabilityReason::MetadataUnknown);
            }
            if ($presence === ProjectEntryPresence::Absent) {
                return self::comparable();
            }
            $samePaths = $run->scope->paths() === $baseline->scope;
            $current = $samePaths || $run->scope->coversPath($file->value());
            $recorded = $samePaths || $recordedScope->coversPath($file->value());
            if (!$current || !$recorded) {
                return self::refused(IncomparabilityReason::OutsideCoverage);
            }
            if (!$run->analyzed($file)) {
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

        if ($run->scope->paths() === $baseline->scope && $delta->equalDefinitions()) {
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
            $current = $run->scope->coversPath($file->value());
            $recorded = $recordedScope->coversPath($file->value());
            if ($current !== $recorded) {
                return self::refused($current
                    ? IncomparabilityReason::PathsDiffer
                    : IncomparabilityReason::OutsideCoverage);
            }
            if ($current && $delta->differsAt($file)) {
                return self::refused(IncomparabilityReason::ExclusionsDiffer);
            }
        }

        return self::comparable();
    }
}
