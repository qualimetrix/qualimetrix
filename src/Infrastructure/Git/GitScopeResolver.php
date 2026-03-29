<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Git;

use InvalidArgumentException;
use Qualimetrix\Analysis\Discovery\FinderFileDiscovery;
use Qualimetrix\Configuration\Pipeline\ResolvedConfiguration;
use Qualimetrix\Core\Util\PathNormalizer;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Resolves analysis scope, file discovery strategy and git client from CLI input.
 *
 * Stateless service — no DI registration needed, instantiate via `new`.
 */
final class GitScopeResolver
{
    /**
     * Resolves analysis scope, file discovery strategy and git client from CLI input.
     */
    public function resolve(InputInterface $input, ResolvedConfiguration $resolved): GitScopeResolution
    {
        $paths = $resolved->paths->paths;

        $analyzeScope = $this->resolveAnalyzeScope($input);
        $reportScope = $this->resolveReportScope($input, $analyzeScope);

        $gitClient = null;
        if ($analyzeScope !== null || $reportScope !== null) {
            $gitClient = new GitClient($resolved->analysis->projectRoot);
        }

        // Always use FinderFileDiscovery for full project collection.
        // When analyze scope is set, resolve scoped file paths separately for violation filtering.
        $fileDiscovery = new FinderFileDiscovery($resolved->paths->excludes);

        $scopeFilePaths = null;
        if ($analyzeScope !== null && $gitClient !== null) {
            $scopeFilePaths = $this->resolveScopeFilePaths($gitClient, $analyzeScope, $paths, $resolved->paths->excludes);
        }

        return new GitScopeResolution(
            paths: $paths,
            fileDiscovery: $fileDiscovery,
            gitClient: $gitClient,
            analyzeScope: $analyzeScope,
            reportScope: $reportScope,
            scopeFilePaths: $scopeFilePaths,
        );
    }

    /**
     * Resolves the analyze scope from CLI options.
     *
     * Returns null if no analyze scope is specified (full analysis).
     */
    private function resolveAnalyzeScope(InputInterface $input): ?GitScope
    {
        $analyze = $input->getOption('analyze');
        if (\is_string($analyze) && $analyze !== '') {
            $parser = new GitScopeParser();
            $scope = $parser->parse($analyze);

            if ($scope === null) {
                throw new InvalidArgumentException(
                    \sprintf('Invalid analyze scope: %s. Expected format: git:<ref>', $analyze),
                );
            }

            return $scope;
        }

        return null;
    }

    /**
     * Resolves the report scope from CLI options.
     *
     * Returns null if no report scope is specified.
     * If analyze scope is set but report scope is not, report scope equals analyze scope (implicit).
     */
    private function resolveReportScope(InputInterface $input, ?GitScope $analyzeScope): ?GitScope
    {
        $report = $input->getOption('report');
        if (\is_string($report) && $report !== '') {
            $parser = new GitScopeParser();
            $scope = $parser->parse($report);

            if ($scope === null) {
                throw new InvalidArgumentException(
                    \sprintf('Invalid report scope: %s. Expected format: git:<ref>', $report),
                );
            }

            return $scope;
        }

        // Implicit: if analyze scope is set, report scope equals analyze scope
        return $analyzeScope;
    }

    /**
     * Resolves relative file paths for the analyze scope.
     *
     * Reuses the same logic as GitFileDiscovery to determine which files are in scope,
     * and returns them as relative paths (via PathNormalizer::relativize()) to match
     * the format used in Violation->location->file.
     *
     * @param list<string> $paths Analysis paths
     * @param list<string> $excludes Directories to exclude
     *
     * @return list<string> Relative paths of in-scope files
     */
    private function resolveScopeFilePaths(GitClient $gitClient, GitScope $scope, array $paths, array $excludes): array
    {
        if (!$gitClient->isRepository()) {
            throw new RuntimeException('Git integration requires a git repository');
        }

        $changedFiles = $gitClient->getChangedFiles($scope->ref);
        $repoRoot = $gitClient->getRoot();

        $scopeFiles = [];

        foreach ($changedFiles as $changed) {
            if ($changed->isDeleted()) {
                continue;
            }

            if (!$changed->isPhp()) {
                continue;
            }

            if (!$this->isInPaths($changed->path, $paths)) {
                continue;
            }

            if ($this->isInExcludedDir($changed->path, $excludes)) {
                continue;
            }

            $fullPath = $repoRoot . '/' . $changed->path;

            if (!file_exists($fullPath)) {
                continue;
            }

            $scopeFiles[] = PathNormalizer::relativize($fullPath);
        }

        sort($scopeFiles);

        return $scopeFiles;
    }

    /**
     * Checks if a file path matches any of the specified paths.
     *
     * @param list<string> $paths
     */
    private function isInPaths(string $file, array $paths): bool
    {
        if ($paths === []) {
            return true;
        }

        foreach ($paths as $path) {
            $normalizedPath = ltrim($path, './');

            if ($normalizedPath === '') {
                return true;
            }

            if ($file === $normalizedPath || str_starts_with($file, rtrim($normalizedPath, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if a file path is inside any of the excluded directories.
     *
     * @param list<string> $excludes
     */
    private function isInExcludedDir(string $file, array $excludes): bool
    {
        foreach ($excludes as $dir) {
            $normalizedDir = rtrim($dir, '/') . '/';

            if (str_starts_with($file, $normalizedDir)) {
                return true;
            }
        }

        return false;
    }
}
