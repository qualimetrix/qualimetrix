<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document\Schema;

/**
 * How the layers that wrote one node combine. A node's policy is what its
 * {@see NodeSchema} factory declares; `~` means "not written" under every one.
 */
enum MergePolicy: string
{
    case LastWriterWins = 'last-writer-wins';
    case DeepMerge = 'deep-merge';
    case Replace = 'replace';
    case Accumulate = 'accumulate';
    case ByName = 'by-name';
    case PerLayer = 'per-layer';

    /** What the policy means for an author, one sentence. */
    public function describe(): string
    {
        return match ($this) {
            self::LastWriterWins => 'The last layer that writes the value wins.',
            self::DeepMerge => 'Merged key by key; a written empty map changes nothing.',
            self::Replace => 'The last layer that writes the list replaces it whole; an empty list replaces too.',
            self::Accumulate => 'Every layer adds its elements; duplicates collapse.',
            self::ByName => 'Merged entry by entry, keyed by name; each entry merges by its own policy.',
            self::PerLayer => 'Kept per layer, unmerged, for its owner to fold.',
        };
    }
}
