<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

/**
 * Why `baseline:cleanup` lists an entry as a removal candidate under ADR 0017.
 *
 * None of these reasons is acted on by itself — `cleanup` never removes an
 * entry on its own. A candidate is only ever a
 * suggestion; the user confirms it by selector.
 */
enum BaselineCleanupReason: string
{
    /** The entry's identity did not appear in the measured set ({@see Baseline::staleEntries()}). */
    case Stale = 'stale';

    /** No rule declares the entry's channel any more. */
    case ChannelNotDeclared = 'channel-not-declared';

    /**
     * The entry's channel is declared, but as a configuration error: no run
     * can ever apply the entry, so it is listed for removal on its own —
     * and, unlike {@see Stale}, it stays listed even while the finding is
     * still being produced.
     */
    case ChannelIsConfigurationError = 'channel-is-configuration-error';

    /** The entry was already inert — see {@see InertEntryReason} for why. */
    case Inert = 'inert';
}
