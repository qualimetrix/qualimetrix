<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Util;

/**
 * Matches file paths against path patterns.
 *
 * Supports two matching modes, selected automatically per pattern:
 * - **Prefix mode** (no glob characters): the pattern is treated as a path prefix
 *   with `/` boundary awareness. `src/Entity` matches `src/Entity` itself and
 *   any path under it (`src/Entity/User.php`, `src/Entity/Sub/Deep.php`),
 *   but NOT `src/EntityManager/Foo.php`.
 * - **Glob mode** (contains `*`, `?`, or `[`): the pattern is matched using
 *   `fnmatch()` with `FNM_NOESCAPE`. Without `FNM_PATHNAME`, `*` matches
 *   across directory separators.
 *
 * Examples:
 *   - `src/Entity` matches `src/Entity`, `src/Entity/User.php`, `src/Entity/Sub/Deep.php`
 *   - `src/Entity` does NOT match `src/EntityManager/Foo.php`
 *   - `src/Metrics/*Visitor.php` matches `src/Metrics/CboVisitor.php`
 *   - `src/Rules/**\/*Options.php` matches `src/Rules/Complexity/CcnOptions.php`
 */
final readonly class PathMatcher
{
    /**
     * @var list<string> Normalized patterns (trailing slashes removed)
     */
    private array $normalizedPatterns;

    /**
     * @param list<string> $patterns Path patterns or prefixes to match against
     */
    public function __construct(array $patterns)
    {
        $this->normalizedPatterns = array_map(
            static fn(string $pattern): string => rtrim($pattern, '/'),
            $patterns,
        );
    }

    /**
     * Returns true if the file path matches at least one pattern.
     */
    public function matches(string $filePath): bool
    {
        if ($filePath === '' || $this->normalizedPatterns === []) {
            return false;
        }

        $normalizedPath = rtrim($filePath, '/');

        foreach ($this->normalizedPatterns as $pattern) {
            if ($pattern === '') {
                continue;
            }

            if ($this->isGlobPattern($pattern)) {
                if (fnmatch($pattern, $normalizedPath, \FNM_NOESCAPE)) {
                    return true;
                }
            } else {
                if ($normalizedPath === $pattern || str_starts_with($normalizedPath, $pattern . '/')) {
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
    private function isGlobPattern(string $pattern): bool
    {
        return str_contains($pattern, '*') || str_contains($pattern, '?') || str_contains($pattern, '[');
    }
}
