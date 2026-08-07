<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

/**
 * The optional `mode` of an entry (ADR 0017).
 *
 * The enum has exactly one case on purpose. An entry with no `mode` is a
 * ceiling — the default and the whole point of the mechanism — so "ceiling"
 * has no written spelling and cannot be requested by name. Anything else a
 * file might carry in `mode` is not a mode this version knows, and the
 * loader turns such an entry inert rather than guessing; enumerating only
 * the recognized value is what makes that check total.
 */
enum BaselineEntryMode: string
{
    /**
     * Accept this identity regardless of magnitude and count.
     *
     * Never selected implicitly — a user writes it into the file to say
     * "this one is not a ratchet".
     */
    case Suppress = 'suppress';
}
