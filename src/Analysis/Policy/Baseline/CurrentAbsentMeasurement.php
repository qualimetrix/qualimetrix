<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageObservation;
use Qualimetrix\Analysis\Finding\Contract\ValueReach;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\ExclusionDelta;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\Region;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\SubjectRegion;
use Qualimetrix\Analysis\Policy\Baseline\Contract\CurrentMeasurement;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** Proves absence for an unrecorded identity whose producer completed without a finding. */
final class CurrentAbsentMeasurement
{
    private function __construct() {}

    /** @return array{string, ?string} */
    public static function measure(BaselineIdentity $identity, ChannelDeclarationRegistryInterface $declarations, RunCoverage $coverage): array
    {
        $level = MetricSubject::levelOfCanonical($identity->subjectKey);
        $reach = $declarations->reachAt($identity->channel, $level);
        $subjectFile = SubjectRegion::subjectFile($identity);
        if ($subjectFile !== null) {
            $presence = $coverage->hasFile($subjectFile);
            if ($presence === ProjectEntryPresence::Unknown) {
                return [CurrentMeasurement::OUTSIDE_COVERAGE, 'metadata-unknown'];
            }
            if ($presence === ProjectEntryPresence::Absent || $reach === ValueReach::Members) {
                return self::fileState($subjectFile, $coverage, $reach, $level);
            }
        }
        $region = SubjectRegion::forIdentity($identity, $reach, $coverage->psr4Roots);

        return $region->file !== null
            ? self::fileState($region->file, $coverage, $reach, $level)
            : self::aggregateState($region, $coverage, $reach, $level);
    }

    /** @return array{string, ?string} */
    private static function fileState(RelativePath $file, RunCoverage $coverage, ValueReach $reach, SymbolLevel $level): array
    {
        $presence = $coverage->hasFile($file);

        return match (true) {
            $presence === ProjectEntryPresence::Absent => self::verifiedCurrentAbsence($file, $coverage, $reach, $level),
            $presence === ProjectEntryPresence::Unknown => [CurrentMeasurement::OUTSIDE_COVERAGE, 'metadata-unknown'],
            $coverage->subjectCoverage->covers($reach, $level, SubjectCoverageObservation::analyzedFile($file)) => [CurrentMeasurement::NOTHING_REPORTED, null],
            default => [CurrentMeasurement::OUTSIDE_COVERAGE, 'outside-coverage'],
        };
    }

    /** @return array{string, ?string} */
    private static function verifiedCurrentAbsence(RelativePath $file, RunCoverage $coverage, ValueReach $reach, SymbolLevel $level): array
    {
        $captured = false;
        foreach ($coverage->universe->denominator as $target) {
            if (RunScope::record([$target['path']], $coverage->universe->projectRoot)->coversPath($file->value())) {
                $captured = true;
                break;
            }
        }
        if (!$captured || !$coverage->scope->coversPath($file->value())) {
            return [CurrentMeasurement::OUTSIDE_COVERAGE, 'outside-coverage'];
        }
        if ((new ExclusionDelta($coverage->exclusions, $coverage->exclusions))->currentlyExcluded($file)) {
            return [CurrentMeasurement::OUTSIDE_COVERAGE, 'outside-coverage'];
        }

        $unknown = false;
        foreach ($coverage->scope->paths() as $path) {
            if (str_starts_with($path, '/') || !RunScope::fromRecorded([$path])->coversPath($file->value())) {
                continue;
            }
            $directory = $path === $file->value() ? \dirname($path) : $path;
            $absolute = $directory === '.'
                ? $coverage->universe->projectRoot
                : $coverage->universe->projectRoot->joinRelative(RelativePath::fromString($directory));
            $presence = $coverage->hasDirectory($absolute);
            if ($presence === ProjectEntryPresence::Present) {
                return $coverage->subjectCoverage->covers($reach, $level, SubjectCoverageObservation::verifiedAbsentFile($file))
                    ? [CurrentMeasurement::NOTHING_REPORTED, null]
                    : [CurrentMeasurement::OUTSIDE_COVERAGE, 'outside-coverage'];
            }
            $unknown = $unknown || $presence === ProjectEntryPresence::Unknown;
        }

        return $unknown
            ? [CurrentMeasurement::OUTSIDE_COVERAGE, 'metadata-unknown']
            : [CurrentMeasurement::OUTSIDE_COVERAGE, 'outside-coverage'];
    }

    /** @return array{string, ?string} */
    private static function aggregateState(Region $region, RunCoverage $coverage, ValueReach $reach, SymbolLevel $level): array
    {
        if (!$coverage->subjectCoverage->covers($reach, $level, SubjectCoverageObservation::nonlocalRegion())) {
            return [CurrentMeasurement::OUTSIDE_COVERAGE, 'outside-coverage'];
        }
        if (self::coversRoots($region, $coverage)) {
            return [CurrentMeasurement::NOTHING_REPORTED, null];
        }
        $snapshot = $coverage->snapshot();
        if (!$snapshot->complete()) {
            return [CurrentMeasurement::OUTSIDE_COVERAGE, 'metadata-unknown'];
        }
        foreach ($snapshot->phpFiles as $file) {
            if ($region->contains($file) && !$coverage->scope->coversPath($file->value())) {
                return [CurrentMeasurement::OUTSIDE_COVERAGE, 'outside-coverage'];
            }
        }

        return [CurrentMeasurement::NOTHING_REPORTED, null];
    }

    private static function coversRoots(Region $region, RunCoverage $coverage): bool
    {
        return $coverage->scope->coversPath('.') || ($region->roots !== [] && array_all(
            $region->roots,
            static fn($root): bool => $coverage->scope->coversPath($root->value()),
        ));
    }
}
