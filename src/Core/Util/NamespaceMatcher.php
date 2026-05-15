<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Util;

/**
 * Matches namespaces against namespace patterns.
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
 * per-pattern primitives so other Core utilities (e.g. {@see \Qualimetrix\Architecture\Domain\Layer\LayerDefinition})
 * can reuse a single source of truth without rebuilding the instance pattern set.
 */
final readonly class NamespaceMatcher
{
    /**
     * @var list<string> Normalized patterns (trailing backslashes removed)
     */
    private array $normalizedPatterns;

    /**
     * @param list<string> $patterns Namespace patterns or prefixes to match against
     */
    public function __construct(array $patterns)
    {
        $this->normalizedPatterns = array_map(
            static fn(string $pattern): string => rtrim($pattern, '\\'),
            $patterns,
        );
    }

    /**
     * Returns true if the namespace matches at least one pattern.
     */
    public function matches(string $namespace): bool
    {
        if ($namespace === '' || $this->normalizedPatterns === []) {
            return false;
        }

        foreach ($this->normalizedPatterns as $pattern) {
            if ($pattern === '') {
                continue;
            }

            if (self::matchesSingle($pattern, $namespace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true if no patterns are configured.
     */
    public function isEmpty(): bool
    {
        return $this->normalizedPatterns === [];
    }

    /**
     * Tests a single pattern against a namespace.
     *
     * The caller is responsible for any normalization (e.g. trailing-backslash
     * stripping). Empty `$pattern` and empty `$namespace` always return false.
     *
     * - **Glob mode** (pattern contains `*`, `?`, or `[`): uses `fnmatch()` with
     *   `FNM_NOESCAPE` (backslashes are literals, not escape characters).
     * - **Prefix mode** (no glob characters): exact match or namespace-boundary
     *   prefix match (`App\Entity` matches `App\Entity\User` but not `App\EntityManager`).
     */
    public static function matchesSingle(string $pattern, string $namespace): bool
    {
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
