<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

/**
 * Whether a run's analysed paths cover a baseline file's recorded `scope`
 * (§5.7 of the baseline-ceiling plan).
 *
 * Both writing commands — `baseline:update` and `baseline:cleanup` — refuse
 * to run when they do not, overridable with `--force`. The hazard is
 * one-directional: a run *narrower* than the recorded scope makes every
 * identity outside it look absent, so `cleanup` would offer to delete the
 * rest of the file as stale and `update` would silently leave it untouched.
 * A *wider* run is harmless — it simply measures more than the file
 * remembers — so only the narrowing direction is refused.
 *
 * **Coverage is by whole path segment, not by string prefix.** `src` covers
 * `src/Foo` (a full path component was named) but does not cover `srcfoo` (a
 * bare substring match), and `src` is not covered by `src/Foo` (a child does
 * not stand in for its parent). Both sides are expected in
 * {@see Baseline::normalizeScope()}'s normal form — no trailing separators,
 * no duplicates — which is what makes a plain segment comparison correct
 * without this class re-deriving that normalisation itself.
 */
final class ScopeCoverage
{
    /**
     * The recorded paths the run scope does not cover. Empty means full
     * coverage. Returned rather than a bare bool because the refusal message
     * a command prints names exactly what is missing (§5.7).
     *
     * @param list<string> $runScope the current run's analysed paths, normalized
     * @param list<string> $recordedScope the baseline file's recorded `scope`, normalized
     *
     * @return list<string>
     */
    public static function uncoveredPaths(array $runScope, array $recordedScope): array
    {
        $uncovered = [];

        foreach ($recordedScope as $recorded) {
            if (!self::isCoveredByAny($recorded, $runScope)) {
                $uncovered[] = $recorded;
            }
        }

        return $uncovered;
    }

    /**
     * @param list<string> $runScope
     * @param list<string> $recordedScope
     */
    public static function covers(array $runScope, array $recordedScope): bool
    {
        return self::uncoveredPaths($runScope, $recordedScope) === [];
    }

    /**
     * @param list<string> $runScope
     */
    private static function isCoveredByAny(string $recorded, array $runScope): bool
    {
        foreach ($runScope as $run) {
            if (self::pathCovers($run, $recorded)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `$candidate` covers `$path` — equal to it, or its ancestor by
     * a whole path segment.
     */
    private static function pathCovers(string $candidate, string $path): bool
    {
        if ($candidate === $path) {
            return true;
        }

        if ($candidate === '/') {
            // The filesystem root covers every path. Baseline::normalizeScope()
            // keeps "/" as a path in its own right rather than collapsing it
            // to "", so it must be handled before the segment check below,
            // which would otherwise look for the path to start with "//".
            return true;
        }

        return str_starts_with($path, $candidate . '/');
    }
}
