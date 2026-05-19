<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Git;

use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Violation\Filter\ViolationFilterInterface;
use Qualimetrix\Core\Violation\Violation;

/**
 * Filters violations to show only those in changed files.
 *
 * This filter is used for --report=git:... to show only violations
 * in files that were changed according to the git scope.
 *
 * By default, it also includes violations for parent namespaces of changed files.
 * This can be disabled with --report-strict.
 */
final class GitScopeFilter implements ViolationFilterInterface
{
    /** @var array<string, true> */
    private array $changedPaths;

    /** @var array<string, true> */
    private array $changedNamespaces;

    public function __construct(
        private readonly GitClient $git,
        private readonly GitScope $scope,
        private readonly AbsolutePath $projectRoot,
        private readonly bool $includeParentNamespaces = true,
    ) {
        $this->buildIndex();
    }

    public function shouldInclude(Violation $violation): bool
    {
        $symbolPath = $violation->symbolPath;

        // Check if violation is in a changed file
        $filePath = $violation->location->pathString();
        if (isset($this->changedPaths[$filePath])) {
            return true; // Show this violation
        }

        // Check parent namespaces (if enabled)
        if ($this->includeParentNamespaces) {
            $namespace = $symbolPath->namespace;
            if ($namespace !== null && isset($this->changedNamespaces[$namespace])) {
                return true;
            }
        }

        return false; // Filter out
    }

    /**
     * Builds index of changed files and namespaces.
     */
    private function buildIndex(): void
    {
        $changedFiles = $this->git->getChangedFiles($this->scope->ref);

        $this->changedPaths = [];
        $this->changedNamespaces = [];

        foreach ($changedFiles as $file) {
            if ($file->isDeleted() || !$file->isPhp()) {
                continue;
            }

            // Path is already project-relative (translated at the git boundary
            // in ChangedFile::fromGitOutput).
            $this->changedPaths[$file->path->value()] = true;

            // Extract namespace from file. Path is project-relative, so join
            // against the explicit project root (NOT git top-level — the two
            // differ when the project sits in a git subdirectory).
            $fullPath = $this->projectRoot->joinRelative($file->path);
            if ($fullPath->isFile()) {
                $namespace = $this->extractNamespace($fullPath);
                if ($namespace !== null) {
                    // Add all parent namespaces
                    $parts = explode('\\', $namespace);
                    while ($parts !== []) {
                        $ns = implode('\\', $parts);
                        $this->changedNamespaces[$ns] = true;
                        array_pop($parts);
                    }
                }
            }
        }
    }

    /**
     * Extracts namespace from a PHP file without full parsing.
     *
     * Reads the file at its absolute path and looks for the namespace declaration.
     */
    private function extractNamespace(AbsolutePath $filePath): ?string
    {
        $content = file_get_contents($filePath->value());

        if ($content === false) {
            return null;
        }

        if (preg_match('/^namespace\s+([^;{]+)[;{]/m', $content, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }
}
