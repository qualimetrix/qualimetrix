<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Violation;

/**
 * Violation severity levels, ordered from least to most severe.
 *
 * Priority (lowest to highest): Info (0) < Warning (1) < Error (2).
 *
 * - {@see Severity::Info} — purely informational diagnostic. Exit code is 0
 *   and Info-only runs never fail unless `fail_on` is explicitly set to
 *   `info`. Use this for advisory signals (e.g., coverage diagnostics)
 *   that should not block CI.
 * - {@see Severity::Warning} — requires attention but is not a hard failure.
 *   Fails the run when `fail_on` is `warning` (or `info`).
 * - {@see Severity::Error} — critical issue. Always fails the run unless
 *   `fail_on` is explicitly set to `none`.
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
