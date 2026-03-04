<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection\Dependency;

use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Dependency\DependencyGraphInterface;
use AiMessDetector\Core\Util\StringSet;

/**
 * In-memory implementation of DependencyGraphInterface.
 *
 * Provides efficient lookups via pre-built indexes:
 * - bySource: dependencies grouped by source class
 * - byTarget: dependencies grouped by target class
 * - namespace Ce/Ca: precomputed from StringSet
 */
final class DependencyGraph implements DependencyGraphInterface
{
    /**
     * @param array<Dependency> $dependencies All dependencies
     * @param array<string, array<Dependency>> $bySource Dependencies indexed by source class
     * @param array<string, array<Dependency>> $byTarget Dependencies indexed by target class
     * @param array<string> $classes All unique class names
     * @param array<string> $namespaces All unique namespace names
     * @param array<string, StringSet> $namespaceCe External classes each namespace depends on
     * @param array<string, StringSet> $namespaceCa External classes that depend on each namespace
     */
    public function __construct(
        private readonly array $dependencies,
        private readonly array $bySource,
        private readonly array $byTarget,
        private readonly array $classes,
        private readonly array $namespaces,
        private readonly array $namespaceCe,
        private readonly array $namespaceCa,
    ) {}

    public function getClassDependencies(string $className): array
    {
        return $this->bySource[$className] ?? [];
    }

    public function getClassDependents(string $className): array
    {
        return $this->byTarget[$className] ?? [];
    }

    public function getClassCe(string $className): int
    {
        $deps = $this->bySource[$className] ?? [];
        $targets = StringSet::fromArray([]);

        foreach ($deps as $dep) {
            $targets = $targets->add($dep->targetClass);
        }

        return $targets->count();
    }

    public function getClassCa(string $className): int
    {
        $deps = $this->byTarget[$className] ?? [];
        $sources = StringSet::fromArray([]);

        foreach ($deps as $dep) {
            $sources = $sources->add($dep->sourceClass);
        }

        return $sources->count();
    }

    public function getNamespaceCe(string $namespace): int
    {
        return ($this->namespaceCe[$namespace] ?? new StringSet())->count();
    }

    public function getNamespaceCa(string $namespace): int
    {
        return ($this->namespaceCa[$namespace] ?? new StringSet())->count();
    }

    public function getAllClasses(): array
    {
        return $this->classes;
    }

    public function getAllNamespaces(): array
    {
        return $this->namespaces;
    }

    public function getAllDependencies(): array
    {
        return $this->dependencies;
    }
}
