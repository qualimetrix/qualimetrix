<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Path;

use InvalidArgumentException;
use LogicException;

/**
 * Boundary factory consolidating string-to-VO conversions previously
 * spread across the (now removed) `Core\Util\PathNormalizer` and ad-hoc call sites.
 *
 * The boundaries:
 * - **CLI input** — {@see fromCliArgument()} resolves a user-supplied path against cwd.
 * - **Project pipeline** — {@see projectRelative()} / {@see tryProjectRelative()} accept
 *   either absolute (under project root) or already-relative strings.
 * - **Git output** — {@see gitRelative()} converts git-toplevel-relative output to
 *   project-relative, returning `null` when the file lies outside the project root.
 * - **File publication** — {@see published()} retains the named final segment
 *   under a canonical containing directory.
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
     * null. RelativePath::fromString would otherwise throw, making this
     * non-throwing boundary depend on which input form the caller used.
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
     * Publishes the named file, preserving its final segment even when it is a
     * symlink. The containing directory must resolve inside the canonical root.
     *
     * @throws LogicException when the parent cannot be resolved or lies outside the root
     */
    public static function published(AbsolutePath $file, AbsolutePath $canonicalRoot): RelativePath
    {
        $parent = \dirname($file->value());
        $canonicalParent = realpath($parent);
        if ($canonicalParent === false) {
            throw new LogicException(\sprintf('Cannot publish "%s": its parent cannot be resolved', $file->value()));
        }

        $root = realpath($canonicalRoot->value());
        if ($root === false) {
            throw new LogicException(\sprintf('Cannot publish "%s": project root cannot be resolved', $file->value()));
        }

        $candidate = AbsolutePath::fromString($canonicalParent . '/' . basename($file->value()));
        $relative = $candidate->tryRelativizeTo(AbsolutePath::fromString($root));
        if ($relative === null) {
            throw new LogicException(\sprintf(
                'Cannot publish "%s" outside project root "%s"',
                $file->value(),
                $canonicalRoot->value(),
            ));
        }

        return $relative;
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
