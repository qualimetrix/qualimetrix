<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Run\Contract\Discovery\SkippedEntry;
use Qualimetrix\Core\Path\RelativePath;
use SplFileInfo;

/** Factory-trusted output of one analysis discovery pass. */
final readonly class DiscoveredAnalysisFiles
{
    /**
     * @param list<SplFileInfo> $eligibleFiles
     * @param list<RelativePath> $generatedExcludedFiles
     * @param list<Finding> $unmatchedExcludeFindings What the run's own exclude patterns failed to remove
     * @param list<SkippedEntry> $skippedEntries What discovery refused to hand on, and why
     *
     * The findings travel with the files because they are a statement about
     * the same act: the exclusions were applied here, and a pattern that
     * removed nothing is invisible from anywhere downstream. The skips travel
     * for the same reason and are not counted in `$discoveredCount`: they
     * never were candidates, and the pipeline gives each one a terminal state
     * of its own.
     */
    private function __construct(
        public array $eligibleFiles,
        public array $generatedExcludedFiles,
        public int $discoveredCount,
        public array $unmatchedExcludeFindings,
        public array $skippedEntries,
    ) {}

    /**
     * @internal Constructed only by AnalysisFileDiscovery after deduplication and generated-file classification.
     *
     * @param list<SplFileInfo> $eligibleFiles
     * @param list<RelativePath> $generatedExcludedFiles
     * @param list<Finding> $unmatchedExcludeFindings
     * @param list<SkippedEntry> $skippedEntries
     */
    public static function fromDiscovery(
        array $eligibleFiles,
        array $generatedExcludedFiles,
        int $discoveredCount,
        array $unmatchedExcludeFindings,
        array $skippedEntries = [],
    ): self {
        return new self(
            $eligibleFiles,
            $generatedExcludedFiles,
            $discoveredCount,
            $unmatchedExcludeFindings,
            $skippedEntries,
        );
    }
}
