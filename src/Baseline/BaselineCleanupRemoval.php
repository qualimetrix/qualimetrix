<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

/**
 * What one `baseline:cleanup --remove` run produced (ADR 0017).
 *
 * Three buckets rather than one, because a selector can fail in two distinct
 * ways a user needs to tell apart: it named nothing in the file, or it named
 * more than one thing. {@see Baseline::findBySelector()} returns a list
 * rather than a single entry precisely because the digest is not a proof of
 * uniqueness — the caller reports the ambiguity and picks nothing, which is
 * what {@see $ambiguous} exists to carry back.
 */
final readonly class BaselineCleanupRemoval
{
    /**
     * @param list<EntrySelector> $removed selectors that named exactly one entry, now gone
     * @param list<EntrySelector> $notFound selectors that named nothing in the file
     * @param list<EntrySelector> $ambiguous selectors that named more than one entry; none of them removed
     */
    public function __construct(
        public Baseline $baseline,
        public array $removed,
        public array $notFound,
        public array $ambiguous,
    ) {}
}
