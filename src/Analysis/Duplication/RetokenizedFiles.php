<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Duplication;

/**
 * Output of pass 2 (re-tokenization): the token streams and raw source of
 * only the files that {@see HashIndexBuildResult::neededFileIndices()}
 * flagged as participating in a hash match.
 *
 * Bundles what used to be two separate local variables inside
 * {@see DuplicationDetector::detect()} — no additional data is retained
 * beyond what the streaming design already keeps in memory for this phase.
 */
final readonly class RetokenizedFiles
{
    /**
     * @param array<int, list<NormalizedToken>> $tokens fileIdx → tokens
     * @param array<int, string> $sources fileIdx → source content (for hint extraction)
     */
    public function __construct(
        public array $tokens,
        public array $sources,
    ) {}
}
