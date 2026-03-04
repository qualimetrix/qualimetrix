<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Dependency;

/**
 * A no-op implementation of DependencyGraphInterface.
 *
 * Used when dependency collection is disabled or not configured.
 * All queries return empty results.
 */
final class EmptyDependencyGraph implements DependencyGraphInterface
{
    public function getClassDependencies(string $className): array
    {
        return [];
    }

    public function getClassDependents(string $className): array
    {
        return [];
    }

    public function getClassCe(string $className): int
    {
        return 0;
    }

    public function getClassCa(string $className): int
    {
        return 0;
    }

    public function getNamespaceCe(string $namespace): int
    {
        return 0;
    }

    public function getNamespaceCa(string $namespace): int
    {
        return 0;
    }

    public function getAllClasses(): array
    {
        return [];
    }

    public function getAllNamespaces(): array
    {
        return [];
    }

    public function getAllDependencies(): array
    {
        return [];
    }
}
