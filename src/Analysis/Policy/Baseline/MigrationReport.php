<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

/**
 * The continuity report `baseline:migrate` implements ADR 0017's migration
 * decision: it shows what a v5 file's acceptances became once the fresh
 * capture that now backs {@see Baseline} was measured against them.
 *
 * The only thing a v5 record and a v10 finding share is the pair
 * `($symbolKey, $rule)` — the v5 key already equals `SymbolPath::toCanonical()`,
 * and `$rule` is the prefix of a v10 channel key up to `#` — so
 * {@see BaselineMigrator} classifies every v5 pair into exactly one of three
 * groups by whether the fresh capture recorded a v10 entry under it:
 *
 * - **carried** — it did. The pair's acceptance is now recorded with a
 *   magnitude; {@see $carriedV5EntryCount} and {@see $carriedV10EntryCount}
 *   count, not enumerate, since nothing about a carried pair needs a user's
 *   attention.
 * - **dropped** — it did not. Fully enumerated in {@see $dropped}: each is a
 *   lost acceptance, either because the debt was fixed or because
 *   configuration stopped producing it — nothing here says which, but a
 *   user needs to know *that* it happened and *where*, which a count alone
 *   cannot say.
 * - **fresh** — a v10 entry the fresh capture wrote under a pair the v5 file
 *   never mentioned: a channel v5 could not hash, or debt added since.
 *   {@see $freshV10EntryCount} counts entries, not pairs, and is not
 *   enumerated — it is not a v5 continuity question at all.
 *
 * {@see $uncapturedGroupCount} is {@see BaselineCapture::$uncaptured}'s
 * count, carried through rather than dropped: a group the capture itself
 * refused to record (ADR 0017) is invisible everywhere else this report
 * looks, and a `migrate` that reported carried/dropped/fresh while silently
 * losing this count would misstate how much of the run actually landed.
 *
 * {@see $unreadableV5Records} is the same argument applied to the *input*:
 * a row of the v5 file that did not parse belongs to none of the three
 * groups above, because it never became a pair to classify. It is
 * enumerated, not counted — a user who is told "3 rows were unreadable"
 * without being told which ones has no way to recover them, and `migrate`
 * does not run twice.
 */
final readonly class MigrationReport
{
    /**
     * @param list<MigrationReportDroppedEntry> $dropped fully enumerated — see the class docblock
     * @param list<V5UnreadableRecord> $unreadableV5Records rows of the v5 file that never
     *                                                      parsed into a record, in file order
     */
    public function __construct(
        public int $carriedV5EntryCount,
        public int $carriedV10EntryCount,
        public array $dropped,
        public int $freshV10EntryCount,
        public int $uncapturedGroupCount,
        public array $unreadableV5Records = [],
    ) {}
}
