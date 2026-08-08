<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Duplication;

/**
 * Output of {@see HashIndexBuilder::build()}: the exact rolling-hash index
 * rebuilt for the saturating pre-pass candidates, plus the per-file path
 * bookkeeping needed to interpret positions in it.
 *
 * Holds exactly the arrays that used to be local variables inside
 * {@see DuplicationDetector::detect()} before the streaming index-build
 * phase was extracted into its own class — bundling them does not add any
 * additional copy or retention beyond what already existed.
 */
final readonly class HashIndexBuildResult
{
    /**
     * @param list<string> $filePaths maps fileIdx → project-relative path (identifier surface for DuplicateLocation)
     * @param list<string> $ioPaths maps fileIdx → path as supplied by the file source (used only for re-read I/O in pass 2)
     * @param array<int, list<int>> $hashIndex candidate hash → all packed positions, pruned to hashes with 2+ occurrences
     */
    public function __construct(
        public array $filePaths,
        public array $ioPaths,
        public array $hashIndex,
    ) {}

    public function isEmpty(): bool
    {
        return $this->hashIndex === [];
    }

    /**
     * Files that participate in at least one hash match — the only ones
     * that need re-tokenizing in pass 2.
     *
     * @return array<int, true> fileIdx → true
     */
    public function neededFileIndices(): array
    {
        $needed = [];

        foreach ($this->hashIndex as $positions) {
            foreach ($positions as $packed) {
                $needed[PackedPosition::fileIndex($packed)] = true;
            }
        }

        return $needed;
    }
}
