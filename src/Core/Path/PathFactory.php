<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Path;

use InvalidArgumentException;

/**
 * Boundary factory consolidating the three string-to-VO conversions previously
 * spread across {@see \Qualimetrix\Core\Util\PathNormalizer} and ad-hoc call sites.
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

    public static function tryProjectRelative(string $raw, AbsolutePath $projectRoot): ?RelativePath
    {
        if (str_starts_with($raw, '/')) {
            return AbsolutePath::fromString($raw)->tryRelativizeTo($projectRoot);
        }

        return RelativePath::fromString($raw);
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
