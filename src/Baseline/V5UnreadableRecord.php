<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

/**
 * A line of a version 5 baseline file that did not parse as a v5 record.
 *
 * `baseline:migrate` runs once and there is no second chance: an acceptance
 * the reader could not read is an acceptance the user loses without being
 * told, which is exactly the silence ADR 0017 forbids the v10 loader. So the
 * reader collects these instead of skipping them, and the command names
 * them.
 *
 * Refusing the whole file over one bad line would be the opposite mistake —
 * one malformed record must not cost a user every other acceptance in the
 * file (ADR 0017). Reading everything readable and reporting the rest is the
 * only behaviour that loses nothing.
 *
 * @see V5BaselineReader
 */
final readonly class V5UnreadableRecord
{
    /**
     * @param string $symbolKey the key the record was listed under — the only part of an
     *                          unparseable line that is reliably present, and enough for a
     *                          user to find it in the file
     * @param string $detail what was wrong, in the reader's own words
     */
    public function __construct(
        public string $symbolKey,
        public string $detail,
    ) {}

    public function describe(): string
    {
        return \sprintf('%s — %s', $this->symbolKey, $this->detail);
    }
}
