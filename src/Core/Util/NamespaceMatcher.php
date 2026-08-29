<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Util;

/**
 * Matches namespaces against namespace patterns, naming the pattern that fired.
 *
 * Trailing backslashes are cosmetic: `App\Entity\` and `App\Entity` are the
 * same pattern. Normalization happens inside {@see matchesSingle()} so every
 * caller gets it, whether it goes through an instance or the static helper.
 *
 * Supports two matching modes, selected automatically per pattern:
 * - **Prefix mode** (no glob characters): the pattern is treated as a namespace prefix
 *   with `\` boundary awareness. `App\Entity` matches `App\Entity` itself and
 *   any namespace under it (`App\Entity\User`), but NOT `App\EntityManager`.
 * - **Glob mode** (contains `*`, `?`, or `[`): the pattern is matched using
 *   `fnmatch()` with `FNM_NOESCAPE`.
 *
 * Examples:
 *   - `App\Entity` matches `App\Entity`, `App\Entity\User`, `App\Entity\Sub\Deep`
 *   - `App\Entity` does NOT match `App\EntityManager\Foo`
 *   - `App\*Repository` matches `App\UserRepository`
 *
 * The static helpers {@see matchesSingle()} and {@see isGlob()} expose the
 * per-pattern primitives so other Core utilities (e.g. {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition})
 * can reuse a single source of truth without rebuilding the instance pattern set.
 */
final readonly class NamespaceMatcher
{
    /**
     * @param list<string> $patterns Namespace patterns or prefixes to match against
     */
    public function __construct(
        private array $patterns,
    ) {}

    /**
     * Returns the pattern that matched the namespace, or `null` if none did.
     *
     * When several configured patterns match, the first one in configuration
     * order is returned — the same order the internal scan already used to
     * short-circuit on the first hit.
     */
    public function matches(string $namespace): ?PatternMatch
    {
        if ($namespace === '' || $this->patterns === []) {
            return null;
        }

        foreach ($this->patterns as $pattern) {
            if (self::matchesSingle($pattern, $namespace)) {
                return new PatternMatch(rtrim($pattern, '\\'));
            }
        }

        return null;
    }

    /**
     * Returns true if no patterns are configured.
     */
    public function isEmpty(): bool
    {
        return $this->patterns === [];
    }

    /**
     * Tests a single pattern against a namespace.
     *
     * Trailing backslashes are stripped from `$pattern` first, so callers do
     * not have to compensate: a pattern that is nothing but backslashes ends up
     * empty and matches nothing. Empty `$pattern` and empty `$namespace` always
     * return false.
     *
     * - **Glob mode** (pattern contains `*`, `?`, or `[`): uses `fnmatch()` with
     *   `FNM_NOESCAPE` (backslashes are literals, not escape characters).
     * - **Prefix mode** (no glob characters): exact match or namespace-boundary
     *   prefix match (`App\Entity` matches `App\Entity\User` but not `App\EntityManager`).
     */
    public static function matchesSingle(string $pattern, string $namespace): bool
    {
        $pattern = rtrim($pattern, '\\');

        if ($pattern === '' || $namespace === '') {
            return false;
        }

        if (self::isGlob($pattern)) {
            return fnmatch($pattern, $namespace, \FNM_NOESCAPE);
        }

        return $namespace === $pattern || str_starts_with($namespace, $pattern . '\\');
    }

    /**
     * Returns true if the pattern contains glob characters (`*`, `?`, `[`).
     *
     * Used to decide between glob (`fnmatch`) and prefix matching modes.
     */
    public static function isGlob(string $pattern): bool
    {
        return str_contains($pattern, '*') || str_contains($pattern, '?') || str_contains($pattern, '[');
    }
}
