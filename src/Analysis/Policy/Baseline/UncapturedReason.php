<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

/**
 * Why a group of findings did not become an entry.
 *
 * Both values are the plan's fail-safe direction — an entry that could never
 * be applied is worse than none — but neither is something a user should have
 * to infer from a later run's output (ADR 0017).
 */
enum UncapturedReason: string
{
    /** No rule declares the channel, so nothing could ever compare against the entry. */
    case UndeclaredChannel = 'undeclared-channel';

    /**
     * The channel is declared, but as a configuration error: recording it
     * would freeze a disagreement between the configuration and the code as
     * an accepted steady state. Resolvable only by fixing the configuration
     * — or, where the configuration offers one, by declining the diagnostic
     * there (`coverage: ignore`).
     */
    case ConfigurationErrorChannel = 'configuration-error-channel';

    /**
     * The channel stores magnitudes and some member of the group reported no
     * finite number. ADR 0017 requires exactly one per member, and inventing one
     * would fabricate the very boundary the entry exists to state.
     */
    case MagnitudeUnavailable = 'magnitude-unavailable';

    public function describe(): string
    {
        return match ($this) {
            self::UndeclaredChannel => 'no rule declares the channel',
            self::ConfigurationErrorChannel => 'the channel reports a configuration error, which cannot be accepted as debt',
            self::MagnitudeUnavailable => 'a finding reported no finite magnitude',
        };
    }
}
