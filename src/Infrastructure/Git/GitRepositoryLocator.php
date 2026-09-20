<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Git;

use InvalidArgumentException;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use RuntimeException;

/**
 * Locates the .git directory for the current repository.
 *
 * Primary strategy: uses `git rev-parse --git-dir` which correctly handles
 * regular repositories, git worktrees, and bare repositories.
 *
 * Fallback: manual directory traversal up the filesystem tree (for environments
 * where git is not in PATH).
 */
final class GitRepositoryLocator implements GitRepositoryLocatorInterface
{
    /**
     * Finds the .git directory for the current repository.
     *
     * @param AbsolutePath|null $workingDir Working directory to start from (defaults to getcwd())
     *
     * @return AbsolutePath|null Absolute path to .git directory, or null if not in a git repo
     */
    public function findGitDir(?AbsolutePath $workingDir = null): ?AbsolutePath
    {
        $workingDir ??= self::currentDirectory();

        if ($workingDir === null) {
            return null;
        }

        return $this->findViaGitCommand($workingDir)
            ?? $this->findViaDirectoryTraversal($workingDir);
    }

    public function findHooksDir(?AbsolutePath $workingDir = null): ?AbsolutePath
    {
        $workingDir ??= self::currentDirectory();

        if ($workingDir === null) {
            return null;
        }

        // `--git-path hooks` is the only spelling that answers for all three
        // of a plain repository, a `core.hooksPath` override and a linked
        // worktree. It answers for a directory that does not exist yet, which
        // is the right answer too: the caller decides what to do about that.
        $answer = $this->askGit(['rev-parse', '--git-path', 'hooks'], $workingDir);

        if ($answer !== null) {
            return $answer;
        }

        // git is not on PATH. The traversal fallback cannot see
        // `core.hooksPath`, so this is the older, narrower answer, kept
        // because no answer at all would be worse.
        return $this->findGitDir($workingDir)?->joinRelative(RelativePath::fromString('hooks'));
    }

    /**
     * Uses `git rev-parse --git-dir` to find the .git directory.
     *
     * This handles regular repos, worktrees, and bare repos correctly.
     */
    private function findViaGitCommand(AbsolutePath $workingDir): ?AbsolutePath
    {
        return $this->askGit(['rev-parse', '--git-dir'], $workingDir);
    }

    /**
     * Runs git and turns its one-line answer into an absolute path.
     *
     * @param list<string> $arguments
     */
    private function askGit(array $arguments, AbsolutePath $workingDir): ?AbsolutePath
    {
        // Exactly one pipe, and it is read. Nothing here consumes git's
        // diagnostics, so handing them a pipe would be a deadlock waiting for
        // a verbose git: once the unread pipe filled, the child would block on
        // the write, never close its output stream, and this method would wait
        // for an EOF that cannot arrive. `/dev/null` never fills.
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $process = @proc_open(
            ['git', ...$arguments],
            $descriptors,
            $pipes,
            $workingDir->value(),
        );

        if (!\is_resource($process)) {
            return null;
        }

        $output = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0 || $output === '') {
            return null;
        }

        // git rev-parse may return a relative path; convert to absolute
        try {
            $candidate = str_starts_with($output, '/')
                ? AbsolutePath::fromString($output)
                : AbsolutePath::fromString($workingDir->value() . '/' . $output);

            // Canonicalised only when it exists: `--git-path` legitimately
            // names a directory nobody has created yet, and refusing that
            // answer would hide a configured hooks path behind "not a git
            // repository".
            return $candidate->exists() ? $candidate->canonicalize() : $candidate;
        } catch (RuntimeException | InvalidArgumentException) {
            return null;
        }
    }

    private static function currentDirectory(): ?AbsolutePath
    {
        $cwd = getcwd();

        return $cwd === false ? null : AbsolutePath::fromString($cwd);
    }

    /**
     * Walks up the directory tree looking for a .git directory.
     *
     * Fallback for environments where git is not available in PATH.
     */
    private function findViaDirectoryTraversal(AbsolutePath $startDir): ?AbsolutePath
    {
        $currentDir = $startDir->value();

        while (true) {
            $gitDir = $currentDir . '/.git';

            if (is_dir($gitDir)) {
                return AbsolutePath::fromString($gitDir);
            }

            if (is_file($gitDir)) {
                // A `.git` file is a hard repository/worktree boundary: it
                // declares "this is the git dir for the project at $currentDir".
                // If the link is broken (unreadable, malformed, or pointing at a
                // missing target), the answer is "no usable git dir here" — NOT
                // "fall back to whichever ancestor happens to have a .git",
                // which would silently let `hook:install` write into a parent
                // repository's hooks directory.
                return $this->resolveWorktreeLink($gitDir, $currentDir);
            }

            $parentDir = \dirname($currentDir);
            if ($parentDir === $currentDir) {
                return null;
            }

            $currentDir = $parentDir;
        }
    }

    /**
     * Resolves a worktree `.git` file (which contains `gitdir: /path/to/...`)
     * to the absolute path of the linked git directory. Returns null if the
     * file is not a valid worktree link or the target is unreachable.
     */
    private function resolveWorktreeLink(string $gitFile, string $currentDir): ?AbsolutePath
    {
        $content = file_get_contents($gitFile);
        if ($content === false || !str_starts_with($content, 'gitdir: ')) {
            return null;
        }

        $linkedDir = trim(substr($content, 8));
        if (!str_starts_with($linkedDir, '/')) {
            $linkedDir = $currentDir . '/' . $linkedDir;
        }

        try {
            return AbsolutePath::fromString($linkedDir)->canonicalize();
        } catch (RuntimeException | InvalidArgumentException) {
            // RuntimeException: canonicalize() target missing.
            // InvalidArgumentException: malformed gitdir: payload escapes root via `..`.
            return null;
        }
    }
}
