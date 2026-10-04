<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication\Normalization;

use Qualimetrix\Analysis\Evidence\Duplication\DuplicationDetector;
use Qualimetrix\Analysis\Evidence\Duplication\Index\HashIndexBuildResult;

/**
 * Output of re-tokenization: the token streams and raw source of only the
 * files that {@see HashIndexBuildResult::neededFileIndices()} flagged as
 * participating in a hash match. A failed re-read aborts this output.
 *
 * Bundles what used to be two separate local variables inside
 * {@see DuplicationDetector::inspect()} — no additional data is retained
 * beyond what the streaming design already keeps in memory for this phase.
 */
final readonly class RetokenizedFiles
{
    /**
     * @param array<int, TokenStream> $streams fileIdx → token stream
     * @param array<int, string> $sources fileIdx → source content (for hint extraction)
     */
    public function __construct(
        public array $streams,
        public array $sources,
    ) {}
}
