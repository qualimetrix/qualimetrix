<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

/**
 * The contents of a version 5 baseline file, read but not applied — v5 is
 * retired (§6 of the baseline-ceiling plan) and only {@see BaselineMigrator}
 * reads it, to produce a version 10 file plus a continuity report.
 */
final readonly class V5Baseline
{
    /**
     * @param list<V5Entry> $entries every record the file held, in file order
     */
    public function __construct(
        public array $entries,
    ) {}
}
