<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\Contract\Entry;

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

    case LevelNotDeclared = 'level-not-declared';

    case BaselineAuditChannel = 'baseline-audit-channel';

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
     * The channel is declared, but declares itself a configuration error
     * rather than debt: no entry may accept it, however the entry got into
     * the file. Distinct from {@see UndeclaredChannel} because the two ask
     * for opposite fixes — an undeclared channel means the entry is stale
     * and should be removed, while this one means the *configuration* is
     * wrong and the finding has to be resolved rather than recorded.
     */
    case ConfigurationErrorChannel = 'configuration-error-channel';

    /**
     * Two or more entries claim the same identity. *All* of them go inert,
     * not just the later ones: with nothing in the file to say which was
     * meant, applying either would be a guess, and the guess would suppress.
     */
    case DuplicateIdentity = 'duplicate-identity';

    /**
     * A closure key read from a document older than version 15. It named the
     * closure's rank among all closures of the file; a closure is now named
     * under its enclosing declaration, and a file-level closure still spells
     * the old form, so the key would bind to whichever closure holds that rank
     * today. Not stale either: a stale entry is one nothing reports, and this
     * one may coincide with a reported closure it never described.
     */
    case RetiredClosureIdentity = 'retired-closure-identity';

    /** Keyed by case value; static analysis refuses a case without one. */
    private const array DESCRIPTIONS = [
        'malformed' => 'malformed entry',
        'undeclared-channel' => 'channel is not declared by any rule',
        'level-not-declared' => 'subject level is not declared by this channel in this configuration',
        'baseline-audit-channel' => 'baseline audit findings cannot be accepted as debt',
        'configuration-error-channel' => 'channel reports a configuration error, which cannot be accepted as debt',
        'shape-mismatch' => 'entry does not match the channel\'s declared shape',
        'unrecognized-mode' => 'unrecognized mode',
        'duplicate-identity' => 'duplicate identity',
        'retired-closure-identity' => 'retired closure identity: baseline versions before 15 named a closure by its rank in the file',
    ];

    /**
     * Short human-readable form for `check` output.
     */
    public function description(): string
    {
        return self::DESCRIPTIONS[$this->value];
    }
}
