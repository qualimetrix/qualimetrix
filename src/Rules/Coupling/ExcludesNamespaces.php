<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Coupling;

/**
 * Shared logic for options that support namespace exclusion via prefix matching.
 *
 * Requires the using class to have `public readonly array $excludeNamespaces`.
 */
trait ExcludesNamespaces
{
    /**
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    private static function parseExcludeNamespaces(array $config): array
    {
        $excludeKey = $config['exclude_namespaces'] ?? $config['excludeNamespaces'] ?? null;

        if (\is_string($excludeKey)) {
            return [$excludeKey];
        }

        if (\is_array($excludeKey)) {
            return array_values($excludeKey);
        }

        return [];
    }

    public function isNamespaceExcluded(string $namespace): bool
    {
        foreach ($this->excludeNamespaces as $prefix) {
            $prefix = rtrim($prefix, '\\');

            if ($namespace === $prefix || str_starts_with($namespace, $prefix . '\\')) {
                return true;
            }
        }

        return false;
    }
}
