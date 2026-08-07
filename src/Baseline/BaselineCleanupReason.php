<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

/**
 * Why `baseline:cleanup` lists an entry as a removal candidate (§5.7, §7 of
 * the baseline-ceiling plan).
 *
 * None of these reasons is acted on by itself — `cleanup` never removes an
 * entry on its own (§5.7's third decision). A candidate is only ever a
 * suggestion; the user confirms it by selector.
 */
enum BaselineCleanupReason: string
{
    /** The entry's identity did not appear in the measured set ({@see Baseline::staleEntries()}). */
    case Stale = 'stale';

    /** No rule declares the entry's channel any more. */
    case ChannelNotDeclared = 'channel-not-declared';

    /** The entry was already inert — see {@see InertEntryReason} for why. */
    case Inert = 'inert';
}
