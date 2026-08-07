<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

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
     */
    public function __construct(
        public Baseline $baseline,
        public array $outcomes,
    ) {}
}
