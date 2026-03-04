<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Namespace_;

use AiMessDetector\Core\Namespace_\ProjectNamespaceResolverInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves project namespaces from composer.json autoload configuration.
 *
 * Determines which namespaces belong to the project (not external dependencies)
 * by reading autoload.psr-4 and autoload-dev.psr-4 sections.
 */
final class ProjectNamespaceResolver implements ProjectNamespaceResolverInterface
{
    /**
     * @var list<string>
     */
    private readonly array $projectPrefixes;

    /**
     * @param string|null $composerJsonPath Path to composer.json (null = auto-detect)
     * @param list<string>|null $overridePrefixes Override detected prefixes
     */
    public function __construct(
        ?string $composerJsonPath = null,
        ?array $overridePrefixes = null,
    ) {
        if ($overridePrefixes !== null) {
            $this->projectPrefixes = $this->normalizeAndSort($overridePrefixes);
            return;
        }

        $path = $composerJsonPath ?? $this->findComposerJson();
        $this->projectPrefixes = $this->extractPrefixesFromComposer($path);
    }

    /**
     * Check if namespace belongs to the project (not external dependency).
     *
     * @param string $namespace Full namespace (e.g., "App\Service\UserService")
     *
     * @return bool True if namespace starts with any project prefix
     */
    public function isProjectNamespace(string $namespace): bool
    {
        $namespace = trim($namespace, '\\');

        // Empty namespace is considered project namespace (global scope)
        if ($namespace === '') {
            return true;
        }

        // Check if namespace starts with any project prefix
        foreach ($this->projectPrefixes as $prefix) {
            if ($this->startsWith($namespace, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get list of detected project namespace prefixes.
     *
     * Sorted by length (descending) to check longer prefixes first.
     *
     * @return list<string> Normalized prefixes without trailing backslash
     */
    public function getProjectPrefixes(): array
    {
        return $this->projectPrefixes;
    }

    /**
     * Find composer.json in current directory or parent directories.
     *
     * @throws RuntimeException If composer.json not found
     */
    private function findComposerJson(): string
    {
        $dir = getcwd();
        if ($dir === false) {
            throw new RuntimeException('Cannot determine current working directory');
        }

        $maxDepth = 10; // Prevent infinite loop
        $depth = 0;

        while ($depth < $maxDepth) {
            $composerPath = $dir . '/composer.json';
            if (file_exists($composerPath)) {
                return $composerPath;
            }

            $parentDir = \dirname($dir);
            if ($parentDir === $dir) {
                // Reached filesystem root
                break;
            }

            $dir = $parentDir;
            $depth++;
        }

        throw new RuntimeException('composer.json not found in current or parent directories');
    }

    /**
     * Extract namespace prefixes from composer.json.
     *
     * @throws InvalidArgumentException If composer.json is invalid
     * @throws RuntimeException If composer.json cannot be read
     *
     * @return list<string> Normalized and sorted prefixes
     */
    private function extractPrefixesFromComposer(string $path): array
    {
        if (!file_exists($path)) {
            throw new InvalidArgumentException("composer.json not found at: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Failed to read composer.json at: {$path}");
        }

        $data = json_decode($content, true);
        if (!\is_array($data)) {
            throw new InvalidArgumentException("Invalid JSON in composer.json at: {$path}");
        }

        $prefixes = [];

        // Extract from autoload.psr-4
        if (isset($data['autoload']['psr-4']) && \is_array($data['autoload']['psr-4'])) {
            $prefixes = array_merge($prefixes, array_keys($data['autoload']['psr-4']));
        }

        // Extract from autoload-dev.psr-4
        if (isset($data['autoload-dev']['psr-4']) && \is_array($data['autoload-dev']['psr-4'])) {
            $prefixes = array_merge($prefixes, array_keys($data['autoload-dev']['psr-4']));
        }

        if ($prefixes === []) {
            throw new InvalidArgumentException("No PSR-4 autoload configuration found in: {$path}");
        }

        return $this->normalizeAndSort($prefixes);
    }

    /**
     * Normalize prefixes (trim backslashes) and sort by length descending.
     *
     * @param list<string> $prefixes
     *
     * @return list<string>
     */
    private function normalizeAndSort(array $prefixes): array
    {
        // Normalize: trim backslashes
        $normalized = array_map(
            fn(string $prefix): string => trim($prefix, '\\'),
            $prefixes,
        );

        // Remove duplicates
        $unique = array_unique($normalized);

        // Sort by length (descending) to check longer prefixes first
        usort($unique, fn(string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return $unique;
    }

    /**
     * Check if string starts with prefix.
     *
     * @param string $string Full namespace
     * @param string $prefix Namespace prefix
     *
     * @return bool True if $string starts with $prefix (with proper namespace boundary)
     */
    private function startsWith(string $string, string $prefix): bool
    {
        // Empty prefix matches everything
        if ($prefix === '') {
            return true;
        }

        // Check if string starts with prefix
        if (!str_starts_with($string, $prefix)) {
            return false;
        }

        // If exact match, it's valid
        if ($string === $prefix) {
            return true;
        }

        // Check namespace boundary: next character must be backslash
        // This prevents "App" from matching "Application"
        return isset($string[\strlen($prefix)]) && $string[\strlen($prefix)] === '\\';
    }
}
