<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Duplication;

/**
 * Bundles everything {@see DuplicateBlockFinder::find()} needs for one
 * detection run: the pruned hash index, the re-tokenized files it points
 * into, and the rule's thresholds.
 *
 * A single find() call needs all five values throughout, so bundling them
 * here (rather than threading them through every private helper
 * individually) is a pure signature simplification — it does not change
 * what is held in memory at once.
 */
final readonly class DuplicateSearchRequest
{
    /**
     * @param array<int, list<int>> $hashIndex hash → list of packed positions
     * @param list<string> $filePaths fileIdx → project-relative path
     */
    public function __construct(
        public array $hashIndex,
        public RetokenizedFiles $retokenized,
        public array $filePaths,
        public int $minTokens,
        public int $minLines,
    ) {}
}
