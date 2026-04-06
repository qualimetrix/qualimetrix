<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Git;

/**
 * Locates the .git directory for the current repository.
 *
 * Primary strategy: uses `git rev-parse --git-dir` which correctly handles
 * regular repositories, git worktrees, and bare repositories.
 *
 * Fallback: manual directory traversal up the filesystem tree (for environments
 * where git is not in PATH).
 */
final class GitRepositoryLocator
{
    /**
     * Finds the .git directory for the current repository.
     *
     * @param string|null $workingDir Working directory to start from (defaults to getcwd())
     *
     * @return string|null Absolute path to .git directory, or null if not in a git repo
     */
    public function findGitDir(?string $workingDir = null): ?string
    {
        if ($workingDir === null) {
            $cwd = getcwd();
            if ($cwd === false) {
                return null;
            }
            $workingDir = $cwd;
        }

        return $this->findViaGitCommand($workingDir)
            ?? $this->findViaDirectoryTraversal($workingDir);
    }

    /**
     * Uses `git rev-parse --git-dir` to find the .git directory.
     *
     * This handles regular repos, worktrees, and bare repos correctly.
     */
    private function findViaGitCommand(string $workingDir): ?string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open(
            ['git', 'rev-parse', '--git-dir'],
            $descriptors,
            $pipes,
            $workingDir,
        );

        if (!\is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $output = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0 || $output === '') {
            return null;
        }

        // git rev-parse --git-dir may return a relative path; convert to absolute
        if (!str_starts_with($output, '/')) {
            $output = $workingDir . '/' . $output;
        }

        $realPath = realpath($output);

        return $realPath !== false ? $realPath : null;
    }

    /**
     * Walks up the directory tree looking for a .git directory.
     *
     * Fallback for environments where git is not available in PATH.
     */
    private function findViaDirectoryTraversal(string $startDir): ?string
    {
        $currentDir = $startDir;

        while (true) {
            $gitDir = $currentDir . '/.git';

            if (is_dir($gitDir)) {
                return $gitDir;
            }

            // Also handle worktree .git files (contains "gitdir: /path/to/...")
            if (is_file($gitDir)) {
                $content = file_get_contents($gitDir);
                if ($content !== false && str_starts_with($content, 'gitdir: ')) {
                    $linkedDir = trim(substr($content, 8));
                    if (!str_starts_with($linkedDir, '/')) {
                        $linkedDir = $currentDir . '/' . $linkedDir;
                    }
                    $realPath = realpath($linkedDir);

                    return $realPath !== false ? $realPath : null;
                }
            }

            $parentDir = \dirname($currentDir);
            if ($parentDir === $currentDir) {
                break;
            }

            $currentDir = $parentDir;
        }

        return null;
    }
}
