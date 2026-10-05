<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\Region;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\SubjectRegion;
use Qualimetrix\Analysis\Policy\Baseline\Contract\CurrentMeasurement;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;

/** Proves absence for an unrecorded identity whose producer completed without a finding. */
final class CurrentAbsentMeasurement
{
    private function __construct() {}

    /** @return array{string, ?string} */
    public static function measure(BaselineIdentity $identity, ChannelDeclarationRegistryInterface $declarations, RunCoverage $coverage): array
    {
        $region = SubjectRegion::forIdentity($identity, $declarations->reachAt($identity->channel, MetricSubject::levelOfCanonical($identity->subjectKey)), $coverage->psr4Roots);

        return $region->file !== null
            ? self::fileState($region->file, $coverage)
            : self::aggregateState($region, $coverage);
    }

    /** @return array{string, ?string} */
    private static function fileState(RelativePath $file, RunCoverage $coverage): array
    {
        $presence = $coverage->hasFile($file);

        return match (true) {
            $presence === ProjectEntryPresence::Absent => [CurrentMeasurement::NOTHING_REPORTED, null],
            $presence === ProjectEntryPresence::Unknown => [CurrentMeasurement::OUTSIDE_COVERAGE, 'metadata-unknown'],
            $coverage->analyzed($file) => [CurrentMeasurement::NOTHING_REPORTED, null],
            default => [CurrentMeasurement::OUTSIDE_COVERAGE, 'outside-coverage'],
        };
    }

    /** @return array{string, ?string} */
    private static function aggregateState(Region $region, RunCoverage $coverage): array
    {
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
