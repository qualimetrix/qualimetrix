<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

/**
 * What a channel carry did, in the terms a reviewer of the resulting diff
 * needs: how many entries moved, which declared rows moved none, and how
 * many lines this build could not read.
 *
 * **The unreadable count is deliberately narrower than what `check` calls
 * inert.** Deciding that is what the loader does, and the loader cannot be
 * consulted here: half the `computed.*` family is declared by configuration
 * resolved during an analysis run, so a carry — which runs no analysis by
 * design — would report every computed entry in a user's file as unreadable
 * and frighten them about lines that are perfectly live. So only defects
 * decidable from the document itself are counted, and the report says so.
 * Nothing is removed on account of being counted here; the lines are carried
 * through untouched either way.
 */
final readonly class ChannelRenameReport
{
    public const string UNREADABLE_NOT_AN_OBJECT = 'the entry is not a JSON object';

    public const string UNREADABLE_NO_CHANNEL = 'the entry has no readable "channel"';

    public const string UNREADABLE_MALFORMED_IDENTITY = 'the entry\'s "occurrence" or "edge" is malformed';

    public const string UNREADABLE_ALREADY_DUPLICATE = 'the entry already shared its identity with another';

    /**
     * @param int $totalEntries every entry line the document carries
     * @param int $renamedEntries entries whose `channel` this carry rewrote
     * @param array<string, int> $rowHits declared old name => the entries it matched, zero included
     * @param array<string, int> $unreadable reason => how many entries the document alone shows it for
     * @param bool $written whether the file was replaced; false when nothing matched
     */
    public function __construct(
        public int $totalEntries,
        public int $renamedEntries,
        public array $rowHits,
        public array $unreadable,
        public bool $written,
    ) {}

    /**
     * The declared renames that matched no entry at all. Not an error — a map
     * is written for a vocabulary, not for one file — but the difference
     * between "carried" and "declared and carried nothing" is exactly what a
     * user checking a migration wants to see.
     *
     * @return list<string>
     */
    public function idleRows(): array
    {
        $idle = [];

        foreach ($this->rowHits as $old => $hits) {
            if ($hits === 0) {
                $idle[] = (string) $old;
            }
        }

        return $idle;
    }
}
