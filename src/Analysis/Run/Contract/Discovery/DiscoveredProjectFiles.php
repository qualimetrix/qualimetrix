<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Discovery;

use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorVerdict;
use Qualimetrix\Analysis\Run\Discovery\ScopeFacts;
use Qualimetrix\Core\Path\RelativePath;
use SplFileInfo;

/** Selection and filesystem facts from one project walk. */
final readonly class DiscoveredProjectFiles
{
    /**
     * @param list<SplFileInfo> $eligibleFiles
     * @param list<RelativePath> $generatedExcludedFiles
     * @param list<RelativePath> $namedExcluded
     * @param list<SkippedEntry> $skippedEntries
     * @param list<ExcludeSelectorVerdict> $selectorVerdicts
     */
    public function __construct(
        public array $eligibleFiles,
        public array $generatedExcludedFiles,
        public array $namedExcluded,
        public array $skippedEntries,
        public array $selectorVerdicts,
        public ScopeFacts $scopeFacts,
        public int $discoveredCount,
    ) {}
}
