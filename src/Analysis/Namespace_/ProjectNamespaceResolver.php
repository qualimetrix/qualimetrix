<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Namespace_;

use Qualimetrix\Core\Namespace_\ProjectNamespaceResolverInterface;

/**
 * Resolves project namespaces from composer.json autoload configuration.
 *
 * Determines which namespaces belong to the project (not external dependencies)
 * by reading autoload.psr-4 and autoload-dev.psr-4 sections.
 *
 * When composer.json is missing or has no PSR-4 configuration, all namespaces
 * are treated as project namespaces (empty prefix list = match everything).
 */
final class ProjectNamespaceResolver implements ProjectNamespaceResolverInterface
{
    /**
     * @var list<string>
     */
    private readonly array $projectPrefixes;

    /**
     * @param string|null $composerJsonPath Absolute path to composer.json (null = project root / composer.json)
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

        $path = $composerJsonPath ?? getcwd() . '/composer.json';
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

        // No prefixes detected — treat all namespaces as project (graceful degradation)
        if ($this->projectPrefixes === []) {
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
     * Extract namespace prefixes from composer.json.
     *
     * Returns empty list if composer.json is missing, unreadable, or has no PSR-4 config.
     *
     * @return list<string> Normalized and sorted prefixes
     */
    private function extractPrefixesFromComposer(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        if (!\is_array($data)) {
            return [];
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
