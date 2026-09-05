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
