<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

/**
 * Violation severity levels, ordered from least to most severe.
 *
 * Priority (lowest to highest): Info (0) < Warning (1) < Error (2).
 *
 * - {@see Severity::Info} — report-only. It is never a `fail_on` threshold
 *   and is never counted against one, so an Info-only run always exits 0.
 *   Declaring a rule at `info` is therefore a statement of intent ("observe,
 *   do not gate") rather than the older trick of keeping the rule at
 *   `warning` behind a threshold nobody can reach.
 * - {@see Severity::Warning} — requires attention but is not a hard failure.
 *   Fails the run when `fail_on` is `warning`.
 * - {@see Severity::Error} — critical issue. Fails the run unless `fail_on`
 *   is `none`.
 *
 * "Never gates" is a promise about `fail_on` only. A baseline breach raises
 * the breaching finding to {@see Severity::Error} on its own, and a channel
 * declaring {@see ChannelAcceptability::ConfigurationError} bypasses the
 * comparison entirely — neither asks what severity the rule chose.
 */
enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';

    public function getExitCode(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Warning => 1,
            self::Error => 2,
        };
    }

    /**
     * Whether `fail_on` accepts this severity as a threshold.
     */
    public function gatesRun(): bool
    {
        return $this !== self::Info;
    }

    /**
     * Returns human-readable display name.
     */
    public function displayName(): string
    {
        return match ($this) {
            self::Info => 'Info',
            self::Warning => 'Warning',
            self::Error => 'Error',
        };
    }
}
