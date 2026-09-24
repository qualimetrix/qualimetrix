<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\ExcludeBinding;

use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\PathPattern;

/**
 * What a walk of the tree could say about each authored selector.
 *
 * Two answers, kept apart because they used to be one. "Nothing here matched
 * it" accuses the author of a stale entry; "this process could not look" says
 * the run has no opinion. Collapsing the second into silence is how a
 * directory the walk could not list came to cancel the finding without
 * leaving anything for the reader — the selector was dropped from the answer
 * exactly as a bound one is.
 *
 * A selector hidden by another *exclude* is not in {@see $unlistable}: the
 * author asked for that subtree to be left out, so the walk not seeing it is
 * the configuration working, not evidence going missing.
 */
final readonly class ExcludeBindingVerdict
{
    /**
     * @param list<PathPattern> $unbound Selectors the walk saw no directory for.
     * @param array<string, AbsolutePath> $unlistable Selector display name to the directory whose
     *                                                contents this process could not read.
     */
    public function __construct(
        public array $unbound,
        public array $unlistable,
    ) {}
}
