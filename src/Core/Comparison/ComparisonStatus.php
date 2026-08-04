<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Comparison;

/**
 * How a recorded finding stands relative to the current run.
 *
 * Produced when a recorded finding is compared against a run, rendered by
 * every output format, and consulted by exit-code policy — three independent
 * subjects name this vocabulary, which is what makes it a cross-cutting
 * primitive rather than the private vocabulary of whichever component
 * computes it.
 *
 * Exactly one status applies to a recorded finding. Conditions do overlap in
 * practice — an entry can sit in an excluded path, belong to a removed rule,
 * and reference a changed contract at once — so the statuses are **ordered**
 * and the first match wins. See {@see precedence()}.
 *
 * This type carries vocabulary and ordering only — the two questions that
 * Baseline's lifecycle commands, Reporting, and the exit-code policy all need
 * answered the same way. It deliberately does **not** carry "may an ordinary
 * command mutate the entry" (§5.6 of the ratchet-baseline plan): that
 * question is asked only by Baseline's lifecycle commands, so by the
 * duplication test in ADR 0016 it belongs entirely inside Baseline, not here.
 * An earlier revision put a `permitsEntryMutation()` method on this enum and
 * it implemented the wrong rule — "false for every status that proves
 * nothing about the code" — while {@see precedence()} on the same type
 * already encoded the correct one: §5.6 requires `Suppressed` to also
 * forbid mutation, and `permitsEntryMutation()` returned `true` for it. Two
 * rules lived in one type, and a unit test pinned the wrong one. The lesson
 * generalises: a lifecycle policy for one consumer does not belong on a
 * cross-cutting vocabulary type merely because that type is convenient to
 * hang it from.
 */
enum ComparisonStatus: string
{
    /** A current finding with no recorded counterpart. Outside the precedence ordering. */
    case New = 'new';

    /** Inside the allowance and not better than recorded. */
    case Matched = 'matched';

    /** Inside the allowance and better than recorded. */
    case Improved = 'improved';

    /** At least one axis or the count exceeded its allowance. */
    case Regressed = 'regressed';

    /** No current finding, under proven coverage. */
    case Resolved = 'resolved';

    /** Comparison succeeded and the result is deliberately silenced. */
    case Suppressed = 'suppressed';

    /** Coverage cannot prove the recorded finding was evaluated. */
    case Unobserved = 'unobserved';

    /** The recorded finding's channel is declared by nothing in this build. */
    case Orphaned = 'orphaned';

    /** The contracts cannot be compared. */
    case Incompatible = 'incompatible';

    /**
     * Position in the evaluation order; lower is decided first.
     *
     * The ordering is not cosmetic. Without it an implementer facing two
     * simultaneously true conditions can pick either defensible answer, and
     * two components will pick differently:
     *
     * 1. {@see Orphaned} — the rule is absent from the build, so nothing else
     *    can be computed about the entry.
     * 2. {@see Unobserved} — the scope was not evaluated, so no comparison is
     *    possible.
     * 3. {@see Incompatible} — the scope was evaluated but the contracts
     *    cannot be compared.
     * 4. {@see Suppressed} — comparison succeeded and is deliberately silenced.
     * 5. The outcome statuses, in the order {@see Regressed},
     *    {@see Resolved}, {@see Improved}, {@see Matched}. {@see Improved} is
     *    tested before {@see Matched}, so "inside the allowance" splits
     *    cleanly instead of overlapping.
     *
     * {@see New} has no position: it applies to current findings with no
     * recorded entry at all, so it never competes with the others.
     */
    public function precedence(): int
    {
        return match ($this) {
            self::Orphaned => 1,
            self::Unobserved => 2,
            self::Incompatible => 3,
            self::Suppressed => 4,
            self::Regressed => 5,
            self::Resolved => 6,
            self::Improved => 7,
            self::Matched => 8,
            self::New => \PHP_INT_MAX,
        };
    }

    /**
     * Whether this status takes part in the precedence ordering at all.
     */
    public function participatesInPrecedence(): bool
    {
        return $this !== self::New;
    }
}
