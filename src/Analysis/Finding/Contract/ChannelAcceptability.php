<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

/**
 * Whether a channel's findings may be accepted as debt at all.
 *
 * "Can this finding be put in the ratchet" used to be encoded by the mere
 * presence of a {@see ChannelDeclaration}: a channel that declared nothing
 * was not baselineable, and everything else was. That conflated two
 * different facts — *how* a channel compares (its shape and direction) and
 * *whether* accepting it is a legitimate answer at all — and it had a
 * concrete consequence: the layer-policy diagnostics declared a shape,
 * so a run could record "we accept that the declared layers do not cover
 * the code" as if it were ordinary code debt.
 *
 * The two values name the two answers:
 *
 * - {@see AcceptableAsDebt} — the finding describes the code. A project may
 *   record the current amount and ratchet it down over time. This is what
 *   almost every channel is;
 * - {@see ConfigurationError} — the finding describes the *configuration*,
 *   not the code: the analyser is saying "I cannot do what you asked". No
 *   amount of it is a legitimate steady state, so it is never accepted by
 *   the ratchet on any path, and it fails the run without consulting
 *   `fail_on` — a comparison against a severity threshold would let the one
 *   signal that means "your config is wrong" be filtered out by a config
 *   setting.
 *
 * A configuration error can still be *declined* — but only by stating the
 * intention in configuration, where the author is the one making the claim
 * (today `coverage: ignore` is the single such statement), never by a
 * baseline entry recorded from a run's own output.
 */
enum ChannelAcceptability: string
{
    /** Ordinary code debt: recordable in the baseline and ratchetable. */
    case AcceptableAsDebt = 'acceptable-as-debt';

    /**
     * A statement about the configuration, not the code: never accepted by
     * the ratchet, never silenced by an inline directive, and always fatal
     * to the run.
     */
    case ConfigurationError = 'configuration-error';

    /**
     * Short human-readable form for reports and rejection messages.
     */
    public function describe(): string
    {
        return match ($this) {
            self::AcceptableAsDebt => 'acceptable as debt',
            self::ConfigurationError => 'a configuration error, which cannot be accepted as debt',
        };
    }
}
