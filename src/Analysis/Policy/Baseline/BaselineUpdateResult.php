<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

/**
 * What one `baseline:update` run produced: the new baseline, and what
 * happened to every entry the loaded one held (ADR 0017).
 *
 * Bundled the way {@see BaselineCapture} bundles a generation, so the report
 * and the file cannot be read from two different computations by accident.
 */
final readonly class BaselineUpdateResult
{
    /**
     * @param list<BaselineEntryUpdateOutcome> $outcomes one per entry the loaded
     *                                                   baseline held, in that order
     * @param bool $changed whether $baseline's entries serialize to anything different
     *                      from the loaded ones — an entry can carry the `Updated`
     *                      disposition while writing back the exact payload it already
     *                      held, and that is not a change {@see BaselineWriter} should
     *                      touch the file for. {@see BaselineUpdater} computes this
     *                      alongside the entry it just wrote, rather than a caller
     *                      re-deriving it from $outcomes or from a positional read of
     *                      $baseline afterward
     */
    public function __construct(
        public Baseline $baseline,
        public array $outcomes,
        public bool $changed,
    ) {}
}
