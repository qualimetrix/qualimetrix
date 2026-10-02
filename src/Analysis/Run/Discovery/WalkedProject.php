<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorVerdict;
use Qualimetrix\Analysis\Run\Contract\Discovery\SkippedEntry;
use Qualimetrix\Core\Path\RelativePath;
use SplFileInfo;

/** Facts observed while selecting run entries and judging authored selectors. */
final readonly class WalkedProject
{
    /**
     * @param list<SplFileInfo> $candidates
     * @param list<SkippedEntry> $skipped
     * @param list<RelativePath> $namedExcluded
     * @param list<ExcludeSelectorVerdict> $verdicts
     */
    public function __construct(
        public array $candidates,
        public array $skipped,
        public array $namedExcluded,
        public array $verdicts,
        public ScopeFacts $facts,
    ) {}
}
