<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Violation;

/**
 * Process-wide toggle controlling whether {@see \Qualimetrix\Analysis\RuleExecution\RuleExecutor}
 * retains the individual `Violation` objects it suppresses via per-rule
 * `exclude_namespaces` / `exclude_paths` (as opposed to just counting them in
 * {@see \Qualimetrix\Analysis\RuleExecution\RuleExclusionStats}).
 *
 * Static holder, mirroring {@see \Qualimetrix\Core\Profiler\ProfilerHolder} and
 * {@see \Qualimetrix\Core\Coupling\FrameworkNamespacesHolder}: RuleExecutor (Analysis
 * layer) must not depend on Symfony Console or know about the `--show-suppressed`
 * CLI option directly — the dependency graph
 * (`Infrastructure -> Analysis -> ... -> Core`) only flows downward. Infrastructure
 * (`RuntimeConfigurator`) sets this holder from the CLI flag before the analysis
 * pipeline runs; RuleExecutor only ever reads a plain bool with no knowledge of
 * where it came from.
 *
 * Defaults to `false`: holding onto full `Violation` objects for a report nobody
 * requested is pure waste — on a large legacy codebase with wide per-rule
 * exclusions, that list can grow into the thousands. Counts alone
 * ({@see \Qualimetrix\Analysis\RuleExecution\RuleExclusionStats::$namespaceExclusionsByRule},
 * `$pathExclusionsByRule`) are always collected regardless of this flag.
 */
final class RuleExclusionCaptureHolder
{
    private static bool $enabled = false;

    public static function set(bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Resets to the default (disabled), useful for tests and for clearing
     * state between successive runs in the same process.
     */
    public static function reset(): void
    {
        self::$enabled = false;
    }
}
