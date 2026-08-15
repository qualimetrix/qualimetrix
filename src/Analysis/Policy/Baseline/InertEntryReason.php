<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

/**
 * Why an entry could not be applied.
 *
 * The governing invariant of ADR 0017 is that *an entry the mechanism cannot
 * apply does not suppress*. This enum enumerates the ways that can happen at
 * load time, so a report can tell a user what is wrong with a line instead
 * of dropping it silently — and so that none of these paths is mistaken for
 * a breach: an inapplicable entry says nothing about whether the debt got
 * worse.
 */
enum InertEntryReason: string
{
    /** The entry's structure or field types are not what ADR 0017 describes. */
    case Malformed = 'malformed';

    /** No rule declares this channel, so nothing knows how to compare it. */
    case UndeclaredChannel = 'undeclared-channel';

    /**
     * The entry and its channel disagree about shape — magnitudes stored for
     * an `occurrence` channel, or missing for a `magnitude` one. Both
     * directions are inert: a magnitude channel bounded only by count would
     * silently accept unbounded growth.
     */
    case ShapeMismatch = 'shape-mismatch';

    /** `mode` carries a value this version does not recognize. */
    case UnrecognizedMode = 'unrecognized-mode';

    /**
     * Two or more entries claim the same identity. *All* of them go inert,
     * not just the later ones: with nothing in the file to say which was
     * meant, applying either would be a guess, and the guess would suppress.
     */
    case DuplicateIdentity = 'duplicate-identity';

    /**
     * Short human-readable form for `check` output.
     */
    public function description(): string
    {
        return match ($this) {
            self::Malformed => 'malformed entry',
            self::UndeclaredChannel => 'channel is not declared by any rule',
            self::ShapeMismatch => 'entry does not match the channel\'s declared shape',
            self::UnrecognizedMode => 'unrecognized mode',
            self::DuplicateIdentity => 'duplicate identity',
        };
    }
}
