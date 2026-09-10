<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Core\Path\RelativePath;
use SplFileInfo;

/** Factory-trusted output of one analysis discovery pass. */
final readonly class DiscoveredAnalysisFiles
{
    /**
     * @param list<SplFileInfo> $eligibleFiles
     * @param list<RelativePath> $generatedExcludedFiles
     * @param list<Finding> $unmatchedExcludeFindings What the run's own exclude patterns failed to remove
     *
     * The findings travel with the files because they are a statement about
     * the same act: the exclusions were applied here, and a pattern that
     * removed nothing is invisible from anywhere downstream.
     */
    private function __construct(
        public array $eligibleFiles,
        public array $generatedExcludedFiles,
        public int $discoveredCount,
        public array $unmatchedExcludeFindings,
    ) {}

    /**
     * @internal Constructed only by AnalysisFileDiscovery after deduplication and generated-file classification.
     *
     * @param list<SplFileInfo> $eligibleFiles
     * @param list<RelativePath> $generatedExcludedFiles
     * @param list<Finding> $unmatchedExcludeFindings
     */
    public static function fromDiscovery(
        array $eligibleFiles,
        array $generatedExcludedFiles,
        int $discoveredCount,
        array $unmatchedExcludeFindings,
    ): self {
        return new self($eligibleFiles, $generatedExcludedFiles, $discoveredCount, $unmatchedExcludeFindings);
    }
}
