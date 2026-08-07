<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

/**
 * The contents of a version 5 baseline file, read but not applied — v5 is
 * retired (ADR 0017) and only {@see BaselineMigrator}
 * reads it, to produce a version 10 file plus a continuity report.
 *
 * The two lists together account for **every** row the file held: what
 * parsed, and what did not. Nothing is dropped on the floor — `migrate` is a
 * one-shot conversion, so a record silently skipped here is an acceptance the
 * user loses without ever being told it existed.
 *
 * When it came from a file, `$sourceContentHash` is the SHA-256 of the exact
 * bytes this object was parsed from. `baseline:migrate` carries that provenance
 * to the fresh v10 capture so {@see BaselineWriter} can compare-and-swap the
 * same snapshot, rather than reading the destination a second time.
 */
final readonly class V5Baseline
{
    /**
     * @param list<V5Entry> $entries every record the file held that parsed, in file order
     * @param list<V5UnreadableRecord> $unreadable every row that did not parse as a v5
     *                                             record, in file order — carried through to
     *                                             {@see MigrationReport} so `migrate` can name
     *                                             them
     * @param ?string $sourceContentHash SHA-256 of the source bytes this result parsed, or
     *                                   `null` when `--force` is replacing an absent file
     */
    public function __construct(
        public array $entries,
        public array $unreadable = [],
        public ?string $sourceContentHash = null,
    ) {}
}
