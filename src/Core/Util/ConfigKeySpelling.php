<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Util;

/**
 * The one fold that makes `exclude_paths`, `exclude-paths` and `excludePaths`
 * the same configuration key, and its inverse.
 *
 * Neutral because the three spellings are accepted by every door configuration
 * arrives through — YAML file, `--rule-opt`, a rule's own `#[CliAlias]` — and
 * no single one of them owns the equivalence. Callers that only fold keys keep
 * doing it inline; this exists for the ones that must also answer *about* a key,
 * where the answer has to come back in the spelling its author used rather than
 * the normalized form nothing was typed in.
 */
final class ConfigKeySpelling
{
    /** Folds `_` and `-` away, leaving the camelCase spelling every consumer compares against. */
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
