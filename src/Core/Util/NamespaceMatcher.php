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

            if ($this->isGlobPattern($pattern)) {
                if (fnmatch($pattern, $namespace, \FNM_NOESCAPE)) {
                    return true;
                }
            } else {
                if ($namespace === $pattern || str_starts_with($namespace, $pattern . '\\')) {
                    return true;
                }
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
     * Returns true if the pattern contains glob characters (*, ?, [).
     */
    /**
     * Note: fnmatch with FNM_NOESCAPE handles backslashes as literals, not escape chars.
     * Glob matching for namespaces relies on this flag being set.
     */
    private function isGlobPattern(string $pattern): bool
    {
        return str_contains($pattern, '*') || str_contains($pattern, '?') || str_contains($pattern, '[');
    }
}
