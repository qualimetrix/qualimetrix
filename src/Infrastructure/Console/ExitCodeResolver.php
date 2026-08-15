<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Reporting\ReportCoverage;

/**
 * Determines process exit code based on violation severities and failOn configuration.
 *
 * Severity priority (low → high): Info (0) → Warning (1) → Error (2).
 *
 * Default (null): only errors cause non-zero exit code (same as `fail_on: error`).
 * - `fail_on: info` — any violation (Info, Warning, Error) fails the run.
 *   Info-only runs return exit code 1 (since Info's own exit code is 0).
 * - `fail_on: warning` — Warning and Error fail; Info-only is exit 0.
 * - `fail_on: error` (default) — only Error fails; Info and Warning are exit 0.
 * - `fail_on: none` (or `false`) — never fail on violations.
 */
final class ExitCodeResolver
{
    /**
     * Determines exit code based on violation severity and failOn configuration.
     *
     * @param list<Violation> $violations
     */
    public function resolve(array $violations, ?ReportCoverage $coverage = null, ?ExitPolicy $policy = null): int
    {
        if ($coverage !== null && !$coverage->isComplete()) {
            return 4;
        }
        $failOn = $policy?->failOn;

        // --fail-on=none: never fail on violations
        if ($failOn === false) {
            return 0;
        }

        // Default is --fail-on=error (null treated as Severity::Error)
        $effectiveFailOn = $failOn ?? Severity::Error;
        $thresholdRank = self::severityRank($effectiveFailOn);

        // Find the highest severity present among violations meeting the threshold.
        $highestMatchingRank = -1;

        foreach ($violations as $violation) {
            $rank = self::severityRank($violation->severity);
            if ($rank >= $thresholdRank && $rank > $highestMatchingRank) {
                $highestMatchingRank = $rank;
            }
        }

        if ($highestMatchingRank < 0) {
            return 0;
        }

        // Use the exit code of the highest matching severity, but ensure
        // non-zero when any matching violation exists (Info's exit code is 0
        // but a failing run must signal failure via a non-zero exit).
        return match ($highestMatchingRank) {
            self::severityRank(Severity::Info) => Severity::Warning->getExitCode(),
            self::severityRank(Severity::Warning) => Severity::Warning->getExitCode(),
            default => Severity::Error->getExitCode(),
        };
    }

    /**
     * Numeric rank for ordering: Info < Warning < Error.
     */
    private static function severityRank(Severity $severity): int
    {
        return match ($severity) {
            Severity::Info => 0,
            Severity::Warning => 1,
            Severity::Error => 2,
        };
    }
}
