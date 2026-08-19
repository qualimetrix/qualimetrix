<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
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
 *
 * Two things bypass that comparison entirely, and both do so because they
 * are not judgements about code quality that a user is entitled to filter
 * out: an incomplete run (exit 4), and a finding on a channel declaring
 * {@see \Qualimetrix\Analysis\Finding\Contract\ChannelAcceptability::ConfigurationError}. The second is the tool
 * saying "I cannot do what you asked" — routing it through `fail_on` would
 * mean the default `fail_on: error` decides whether a broken configuration
 * is worth mentioning, and a `fail_on: none` could switch it off completely.
 */
final class ExitCodeResolver
{
    public function __construct(
        private readonly ChannelDeclarationRegistryInterface $declarations,
    ) {}

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

        if ($this->hasConfigurationError($violations)) {
            return Severity::Error->getExitCode();
        }

        return self::failOnExitCode($violations, $policy?->failOn);
    }

    /**
     * The ordinary path: the highest severity present that meets the
     * threshold decides, and nothing meeting it means success.
     *
     * @param list<Violation> $violations
     * @param Severity|false|null $failOn `false` for `--fail-on=none`, `null` for the default (`error`)
     */
    private static function failOnExitCode(array $violations, Severity|false|null $failOn): int
    {
        if ($failOn === false) {
            return 0;
        }

        $highestMatchingRank = self::highestRankAtOrAbove($violations, self::severityRank($failOn ?? Severity::Error));

        if ($highestMatchingRank < 0) {
            return 0;
        }

        // The exit code of the highest matching severity, except that Info's
        // own exit code is 0 — a run failing on `--fail-on=info` must still
        // signal failure.
        return $highestMatchingRank === self::severityRank(Severity::Error)
            ? Severity::Error->getExitCode()
            : Severity::Warning->getExitCode();
    }

    /**
     * The highest severity rank present that is at least `$thresholdRank`,
     * or `-1` when nothing reaches it.
     *
     * @param list<Violation> $violations
     */
    private static function highestRankAtOrAbove(array $violations, int $thresholdRank): int
    {
        $highest = -1;

        foreach ($violations as $violation) {
            $rank = self::severityRank($violation->severity);

            if ($rank >= $thresholdRank && $rank > $highest) {
                $highest = $rank;
            }
        }

        return $highest;
    }

    /**
     * Whether any finding reports a configuration error — checked before
     * `fail_on` is read at all.
     *
     * The severity assertion is not defensive noise. A configuration error
     * that a report prints as `Info` would be a finding whose displayed
     * weight contradicts what it does to the build, and the producing rule
     * is the only place that can be wrong about it — every channel declared
     * {@see \Qualimetrix\Analysis\Finding\Contract\ChannelAcceptability::ConfigurationError} is emitted with
     * `Warning` or `Error` today, and this is what keeps that true.
     *
     * @param list<Violation> $violations
     */
    private function hasConfigurationError(array $violations): bool
    {
        $found = false;

        foreach ($violations as $violation) {
            $declaration = $this->declarations->declarationFor($violation->channel());

            if ($declaration === null || !$declaration->isConfigurationError()) {
                continue;
            }

            if ($violation->severity === Severity::Info) {
                throw new LogicException(\sprintf(
                    'Channel "%s" declares a configuration error but emitted a finding at severity "info".'
                    . ' A configuration error fails the run unconditionally, so reporting it below "warning"'
                    . ' would print a weight the finding does not have.',
                    $violation->channel()->toKey(),
                ));
            }

            $found = true;
        }

        return $found;
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
