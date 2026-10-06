<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use Qualimetrix\Analysis\Policy\Baseline\Ceiling\ExclusionDelta;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence;
use Qualimetrix\Core\Path\RelativePath;

/** Proof that a file identity's own source population was removed by a new exclusion. */
final class ExclusionRemovedPopulation
{
    private function __construct() {}

    public static function proves(BaselineEntry $entry, Baseline $baseline, RunCoverage $coverage): bool
    {
        if (!str_starts_with($entry->identity->subjectKey, 'file:')
            || !$coverage->analysis->isComplete()
            || $coverage->scope->paths() !== $baseline->scope) {
            return false;
        }

        $file = RelativePath::fromString(substr($entry->identity->subjectKey, 5));
        if (!$coverage->scope->coversPath($file->value())) {
            return false;
        }

        $delta = new ExclusionDelta($baseline->exclusions, $coverage->exclusions);
        if ($delta->generatedPolicyChanged() || !$delta->differsAt($file) || !$delta->currentlyExcluded($file)) {
            return false;
        }

        if (!$coverage->completeSnapshotContains($file)) {
            return false;
        }

        return $coverage->hasFile($file) === ProjectEntryPresence::Present;
    }
}
