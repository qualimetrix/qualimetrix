<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration;

/**
 * The fold that makes `exclude_paths`, `exclude-paths` and `excludePaths` the
 * same configuration key, and its inverse.
 *
 * Every door configuration arrives through folds keys this way — the YAML
 * loader, the rule-option factory, the `--rule-opt` parser — and each of them
 * used to spell the expression out again. The inverse exists for the doors that
 * must also answer *about* a key: a refusal has to come back in the spelling
 * its author used rather than the normalized form nothing was typed in.
 *
 * Configuration owns it because configuration is where a key's spelling is a
 * subject at all; the rule layer reads it across the one import edge that
 * already exists in that direction.
 *
 * @qmx-threshold coupling.class-rank warning=0.023 error=0.023 -- Agreement on one spelling, not change impact: this class has no project dependency of its own, and four fifths of its rank arrives through the single edge from RuleOptionKeySet, which every options class asks about its own keys. Every door that stopped writing the fold out again is one of those paths, which is the point of the class. Measured rank 0.0074 against the 0.0066 the 0.02 default scales to at 914 classes; 0.023 is that measurement written back in unscaled units with the slim headroom the Measurement contract hubs take, so a further rise still reports.
 */
final class ConfigKeySpelling
{
    /**
     * Folds `_` and `-` away, leaving the camelCase spelling every consumer
     * compares against. Surrounding whitespace goes with them: a key typed with
     * a stray space is the same key.
     */
    public static function normalize(string $key): string
    {
        return lcfirst(str_replace(['_', '-'], '', ucwords(trim($key), '_-')));
    }

    /**
     * Rewrites a normalized key in the separator style `$authored` was written in.
     *
     * A camelCase original leaves the key alone: there is no separator to infer
     * from it, and camelCase is what `normalize()` produces anyway.
     */
    public static function rewriteLike(string $normalized, string $authored): string
    {
        $separator = match (true) {
            str_contains($authored, '_') => '_',
            str_contains($authored, '-') => '-',
            default => null,
        };

        return $separator === null
            ? $normalized
            : strtolower((string) preg_replace('/[A-Z]/', $separator . '$0', $normalized));
    }
}
