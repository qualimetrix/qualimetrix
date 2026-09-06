<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

/**
 * Where an entry sorts among its siblings under one subject key.
 *
 * Order does not depend on whether an entry happens to be applicable: all
 * entries under a symbol sort by channel, then occurrence, then edge,
 * whatever their state, so the file a command writes does not depend on
 * which configuration produced it. Only an entry whose channel could not be
 * read at all has nothing to sort on; those follow, ordered by selector,
 * which the leading digit keeps behind the rest without either group
 * depending on the other's contents.
 *
 * Owned by its own type because two producers compute it: {@see BaselineWriter}
 * from typed entries, and {@see BaselineChannelRenamer} from the decoded
 * payloads of a file it never turned into objects. Two spellings of one
 * ordering would let a rename leave a file the writer would have laid out
 * differently — a difference nothing would report until a later, unrelated
 * command rewrote the file and produced a diff.
 */
final class BaselineEntryOrder
{
    public static function forComponents(string $channelKey, ?string $occurrenceKey, ?string $edgeKey): string
    {
        return '0' . $channelKey . "\x1F" . ($occurrenceKey ?? '') . "\x1F" . ($edgeKey ?? '');
    }

    public static function forUnreadable(EntrySelector $selector): string
    {
        return '1' . $selector->value;
    }
}
