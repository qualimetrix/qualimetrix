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
     * The three spellings of a schema key an author may write: snake_case,
     * kebab-case and camelCase of its lowercase words. `fail_on` accepts
     * `fail_on`, `fail-on` and `failOn`; `Fail_On`, `FAILON` and `failon` are
     * none of them.
     *
     * @param string $canonical lowercase words joined by `_` or `-`
     *
     * @return non-empty-list<string>
     */
    public static function acceptedSpellings(string $canonical): array
    {
        $words = explode('_', str_replace('-', '_', $canonical));
        $camel = $words[0] . implode('', array_map(ucfirst(...), \array_slice($words, 1)));

        return array_values(array_unique([implode('_', $words), implode('-', $words), $camel]));
    }

    /**
     * True when two spellings differ only in letter case and separators — the
     * same key written in a style none of the accepted spellings has.
     */
    public static function sameWords(string $a, string $b): bool
    {
        return strtolower(str_replace(['_', '-'], '', $a)) === strtolower(str_replace(['_', '-'], '', $b));
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

    /**
     * {@see self::rewriteLike()} for a list of keys the author is offered —
     * a suggestion, the allowed keys of a section. A key written as one plain
     * word shows no style to copy, and then the documented snake_case is the
     * better guess than camelCase: `failon` is answered with `fail_on`, not
     * `failOn`.
     */
    public static function offerLike(string $normalized, string $authored): string
    {
        return preg_match('/[A-Z_-]/', $authored) === 1
            ? self::rewriteLike($normalized, $authored)
            : self::rewriteLike($normalized, '_');
    }
}
