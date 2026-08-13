<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use Qualimetrix\Core\Path\RelativePath;
use SplFileInfo;

/** Factory-trusted output of one analysis discovery pass. */
final readonly class DiscoveredAnalysisFiles
{
    /**
     * @param list<SplFileInfo> $eligibleFiles
     * @param list<RelativePath> $generatedExcludedFiles
     */
    private function __construct(
        public array $eligibleFiles,
        public array $generatedExcludedFiles,
        public int $discoveredCount,
    ) {}

    /**
     * @internal Constructed only by AnalysisFileDiscovery after deduplication and generated-file classification.
     *
     * @param list<SplFileInfo> $eligibleFiles
     * @param list<RelativePath> $generatedExcludedFiles
     */
    public static function fromDiscovery(
        array $eligibleFiles,
        array $generatedExcludedFiles,
        int $discoveredCount,
    ): self {
        return new self($eligibleFiles, $generatedExcludedFiles, $discoveredCount);
    }
}
