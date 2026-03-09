<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Git;

use AiMessDetector\Analysis\Discovery\FileDiscoveryInterface;
use Generator;
use RuntimeException;
use SplFileInfo;

/**
 * Discovers files based on git changes.
 *
 * Returns only files that were changed according to the git scope.
 * Useful for analyzing only changed files (e.g., in pre-commit hooks or PR reviews).
 */
final class GitFileDiscovery implements FileDiscoveryInterface
{
    /**
     * @param list<string> $excludedDirs Directories to exclude from discovery
     */
    public function __construct(
        private readonly GitClient $git,
        private readonly GitScope $scope,
        private readonly array $excludedDirs = [],
    ) {}

    public function discover(string|array $paths): iterable
    {
        if (!$this->git->isRepository()) {
            throw new RuntimeException(
                'Git integration requires a git repository',
            );
        }

        $paths = \is_string($paths) ? [$paths] : $paths;

        yield from $this->discoverChangedFiles($paths);
    }

    /**
     * Discovers changed PHP files that match the given paths.
     *
     * @param list<string> $paths
     *
     * @return Generator<string, SplFileInfo>
     */
    private function discoverChangedFiles(array $paths): Generator
    {
        $changedFiles = $this->git->getChangedFiles($this->scope->ref);
        $repoRoot = $this->git->getRoot();

        $files = [];

        foreach ($changedFiles as $changed) {
            // Skip deleted files
            if ($changed->isDeleted()) {
                continue;
            }

            // Skip non-PHP files
            if (!$changed->isPhp()) {
                continue;
            }

            // Check if file matches any of the specified paths
            if (!$this->isInPaths($changed->path, $paths)) {
                continue;
            }

            // Skip files in excluded directories
            if ($this->isInExcludedDir($changed->path)) {
                continue;
            }

            $fullPath = $repoRoot . '/' . $changed->path;

            // Verify file exists (could be deleted locally but not committed)
            if (!file_exists($fullPath)) {
                continue;
            }

            $files[$fullPath] = new SplFileInfo($fullPath);
        }

        // Sort files for consistent order
        ksort($files);

        foreach ($files as $path => $file) {
            yield $path => $file;
        }
    }

    /**
     * Checks if a file path matches any of the specified paths.
     *
     * @param list<string> $paths
     */
    private function isInPaths(string $file, array $paths): bool
    {
        // If no paths specified, include all files
        if ($paths === []) {
            return true;
        }

        foreach ($paths as $path) {
            // Normalize path (remove leading ./)
            $normalizedPath = ltrim($path, './');

            // Empty path (e.g., ".") matches all files
            if ($normalizedPath === '') {
                return true;
            }

            // Check exact match or directory prefix with boundary check
            if ($file === $normalizedPath || str_starts_with($file, rtrim($normalizedPath, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if a file path is inside any of the excluded directories.
     */
    private function isInExcludedDir(string $file): bool
    {
        foreach ($this->excludedDirs as $dir) {
            $normalizedDir = rtrim($dir, '/') . '/';

            if (str_starts_with($file, $normalizedDir)) {
                return true;
            }
        }

        return false;
    }
}
