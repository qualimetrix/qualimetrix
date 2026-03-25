<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Util;

/**
 * Matches file paths against glob patterns.
 *
 * Used for exclude_paths feature to suppress violations by file path patterns.
 * Uses fnmatch() with FNM_NOESCAPE flag, so `*` matches across directory separators.
 *
 * Examples:
 *   - `src/Entity/*` matches `src/Entity/User.php` and `src/Entity/Sub/Deep.php`
 *   - `*.php` matches any PHP file at any depth
 *   - `src/DTO/UserDTO.php` matches exact path
 */
final readonly class PathMatcher
{
    /**
     * @var list<string> Normalized patterns (trailing slashes removed)
     */
    private array $normalizedPatterns;

    /**
     * @param list<string> $patterns Glob patterns to match against
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
            if (fnmatch($pattern, $normalizedPath, \FNM_NOESCAPE)) {
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
}
