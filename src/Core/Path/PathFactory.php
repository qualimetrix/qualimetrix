<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Path;

use InvalidArgumentException;

/**
 * Boundary factory consolidating the three string-to-VO conversions previously
 * spread across the (now removed) `Core\Util\PathNormalizer` and ad-hoc call sites.
 *
 * The three boundaries:
 * - **CLI input** — {@see fromCliArgument()} resolves a user-supplied path against cwd.
 * - **Project pipeline** — {@see projectRelative()} / {@see tryProjectRelative()} accept
 *   either absolute (under project root) or already-relative strings.
 * - **Git output** — {@see gitRelative()} converts git-toplevel-relative output to
 *   project-relative, returning `null` when the file lies outside the project root.
 *
 * See ADR 0015.
 */
final class PathFactory
{
    /**
     * @throws InvalidArgumentException if $raw resolves outside $projectRoot
     */
    public static function projectRelative(string $raw, AbsolutePath $projectRoot): RelativePath
    {
        $result = self::tryProjectRelative($raw, $projectRoot);

        if ($result === null) {
            throw new InvalidArgumentException(
                \sprintf('Path "%s" resolves outside project root "%s"', $raw, $projectRoot->value()),
            );
        }

        return $result;
    }

    /**
     * Never throws. For absolute inputs outside $projectRoot returns null;
     * for relative inputs that would escape via leading `..`, also returns
     * null (RelativePath::fromString would otherwise throw — the asymmetry
     * surprised callers in the Phase 6 review).
     */
    public static function tryProjectRelative(string $raw, AbsolutePath $projectRoot): ?RelativePath
    {
        try {
            if (str_starts_with($raw, '/')) {
                return AbsolutePath::fromString($raw)->tryRelativizeTo($projectRoot);
            }

            return RelativePath::fromString($raw);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Best-effort project-relative conversion that never throws.
     *
     * Used at file-result boundaries where the caller wants a structurally
     * meaningful {@see RelativePath} for any input — including symlinked
     * sources and the rare file that lies outside the configured project
     * root. The basename-only fallback used in earlier Phase-6 drafts
     * collapsed distinct out-of-root files (`/a/X.php` and `/b/X.php`) to
     * the same key, silently breaking suppression maps and repository
     * indexing. This helper preserves directory structure instead.
     *
     * Behavior:
     * 1. Canonicalize absolute inputs via realpath() so a symlinked source
     *    (`/var/build/src/Foo.php`) relativizes against a canonicalized
     *    project root (`/opt/project`).
     * 2. Try project-relative resolution; return the VO on success.
     * 3. Otherwise drop the leading `/` and any leading `..` segments,
     *    keeping the rest of the structure (mirrors the legacy
     *    `PathNormalizer` "leading-slash-strip" fallback).
     */
    public static function bestEffortRelative(string $absolute, AbsolutePath $projectRoot): RelativePath
    {
        $candidate = $absolute;

        if (str_starts_with($candidate, '/')) {
            $real = @realpath($candidate);
            if ($real !== false) {
                $candidate = $real;
            }
        }

        $relative = self::tryProjectRelative($candidate, $projectRoot);
        if ($relative !== null) {
            return $relative;
        }

        return RelativePath::fromString(self::structurePreservingFallback($candidate));
    }

    /**
     * Strips the leading `/` and resolves `.` / `..` segments lexically,
     * dropping any leading `..` that would escape the filesystem root.
     * The result is non-empty and constructable by {@see RelativePath::fromString}.
     */
    private static function structurePreservingFallback(string $raw): string
    {
        $normalized = str_replace('\\', '/', $raw);
        $segments = [];

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments !== []) {
                    array_pop($segments);
                }

                continue;
            }
            $segments[] = $segment;
        }

        if ($segments === []) {
            // Path collapsed entirely (e.g. "/", "/./../", "/.."). Return a
            // stable placeholder so the result is still a valid RelativePath.
            return 'unknown';
        }

        return implode('/', $segments);
    }

    /**
     * Converts a git-toplevel-relative path to project-relative.
     * Returns `null` when the resulting path lies outside the project root
     * (e.g., the project root is a subdirectory of the git tree).
     */
    public static function gitRelative(
        string $rawGitPath,
        AbsolutePath $gitToplevel,
        AbsolutePath $projectRoot,
    ): ?RelativePath {
        if ($rawGitPath === '') {
            return null;
        }

        $absolute = str_starts_with($rawGitPath, '/')
            ? AbsolutePath::fromString($rawGitPath)
            : $gitToplevel->joinRelative(RelativePath::fromString($rawGitPath));

        return $absolute->tryRelativizeTo($projectRoot);
    }

    public static function fromCliArgument(string $raw, AbsolutePath $cwd): AbsolutePath
    {
        if ($raw === '') {
            throw new InvalidArgumentException('CLI path argument cannot be empty');
        }

        if (str_starts_with($raw, '/')) {
            return AbsolutePath::fromString($raw);
        }

        if ($raw === '.' || $raw === './') {
            return $cwd;
        }

        // Route through AbsolutePath's lexical normalizer so inputs containing
        // `..` ("qmx check ../shared-src" from a subdir) resolve correctly.
        // RelativePath would reject these as out-of-base before they reach cwd.
        return AbsolutePath::fromString($cwd->value() . '/' . $raw);
    }
}
