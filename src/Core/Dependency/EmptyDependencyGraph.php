<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Dependency;

use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * A no-op implementation of DependencyGraphInterface.
 *
 * Used when dependency collection is disabled or not configured.
 * All queries return empty results.
 */
final class EmptyDependencyGraph implements DependencyGraphInterface
{
    public function getClassDependencies(SymbolPath $class): array
    {
        return [];
    }

    public function getClassDependents(SymbolPath $class): array
    {
        return [];
    }

    public function getClassCe(SymbolPath $class): int
    {
        return 0;
    }

    public function getClassCa(SymbolPath $class): int
    {
        return 0;
    }

    public function getNamespaceCe(SymbolPath $namespace): int
    {
        return 0;
    }

    public function getNamespaceCa(SymbolPath $namespace): int
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
