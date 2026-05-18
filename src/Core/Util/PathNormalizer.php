<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Util;

use InvalidArgumentException;

/**
 * Normalizes file paths to be relative to the project root.
 *
 * Ensures consistent canonical keys in baseline regardless of whether the user
 * passes absolute paths, ./src/, or src/ to the CLI.
 *
 * @internal Superseded by {@see \Qualimetrix\Core\Path\PathFactory}. Slated for removal
 *           in the final phase of ADR 0015; new call sites should use the typed VOs.
 *
 * @qmx-threshold coupling.cbo warning=50 error=80 ADR 0015 Phase 1a — used as the
 *                 producer-side bridge before {@see \Qualimetrix\Core\Path\RelativePath::fromString}
 *                 in every rule construction site. High afferent coupling is the
 *                 expected transient state; both this class and the bridge calls
 *                 disappear in Phase 1c when upstream string fields become VOs.
 */
final class PathNormalizer
{
    /**
     * @param string $path Path to relativize
     * @param string|null $projectRoot Base directory (defaults to getcwd(), which is the project root
     *                                 after Application::doRun() applies --working-dir)
     */
    public static function relativize(string $path, ?string $projectRoot = null): string
    {
        // Strip ./ prefix
        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        // Relativize absolute paths against project root
        $base = $projectRoot ?? (string) getcwd();
        $prefix = rtrim($base, '/') . '/';
        if (str_starts_with($path, $prefix)) {
            $path = substr($path, \strlen($prefix));
        }

        // Fallback: when the path is outside the project root, drop the leading "/"
        // so downstream RelativePath VOs (ADR 0015 Phase 1a+) can still construct.
        // The path retains its original structure minus the absolute-marker prefix.
        if (str_starts_with($path, '/')) {
            $path = ltrim($path, '/');
        }

        // Resolve "." and ".." segments lexically so this normalizer agrees with
        // RelativePath's own normalization (ADR 0015). Suppression keys and
        // violation paths must compare equal even when one input went through
        // RelativePath and the other did not. Leading ".." after relativization
        // signals an out-of-project path; throw so callers don't silently
        // misattribute violations to a path that escapes the base (mirrors
        // RelativePath::normalize() invariant).
        if (str_contains($path, '/.') || str_starts_with($path, '.')) {
            $segments = explode('/', $path);
            $resolved = [];
            foreach ($segments as $segment) {
                if ($segment === '' || $segment === '.') {
                    continue;
                }
                if ($segment === '..') {
                    if ($resolved === []) {
                        throw new InvalidArgumentException(
                            \sprintf('Path "%s" escapes the project root via leading "..".', $path),
                        );
                    }
                    array_pop($resolved);

                    continue;
                }
                $resolved[] = $segment;
            }
            $path = implode('/', $resolved);
        }

        return $path;
    }
}
