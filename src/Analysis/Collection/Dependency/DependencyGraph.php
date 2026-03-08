<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection\Dependency;

use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Dependency\DependencyGraphInterface;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Util\StringSet;

/**
 * In-memory implementation of DependencyGraphInterface.
 *
 * Provides efficient lookups via pre-built indexes using canonical string keys.
 * The public API accepts/returns SymbolPath, but internally uses toCanonical()
 * as hash map keys for performance.
 */
final class DependencyGraph implements DependencyGraphInterface
{
    /**
     * @param array<Dependency> $dependencies All dependencies
     * @param array<string, array<Dependency>> $bySource Dependencies indexed by source canonical key
     * @param array<string, array<Dependency>> $byTarget Dependencies indexed by target canonical key
     * @param array<SymbolPath> $classes All unique class SymbolPaths
     * @param array<SymbolPath> $namespaces All unique namespace SymbolPaths
     * @param array<string, StringSet> $namespaceCe External classes each namespace depends on
     * @param array<string, StringSet> $namespaceCa External classes that depend on each namespace
     * @param array<string, int> $classCe Precomputed efferent coupling per class (canonical key -> count)
     * @param array<string, int> $classCa Precomputed afferent coupling per class (canonical key -> count)
     */
    public function __construct(
        private readonly array $dependencies,
        private readonly array $bySource,
        private readonly array $byTarget,
        private readonly array $classes,
        private readonly array $namespaces,
        private readonly array $namespaceCe,
        private readonly array $namespaceCa,
        private readonly array $classCe,
        private readonly array $classCa,
    ) {}

    public function getClassDependencies(SymbolPath $class): array
    {
        return $this->bySource[$class->toCanonical()] ?? [];
    }

    public function getClassDependents(SymbolPath $class): array
    {
        return $this->byTarget[$class->toCanonical()] ?? [];
    }

    public function getClassCe(SymbolPath $class): int
    {
        return $this->classCe[$class->toCanonical()] ?? 0;
    }

    public function getClassCa(SymbolPath $class): int
    {
        return $this->classCa[$class->toCanonical()] ?? 0;
    }

    public function getNamespaceCe(SymbolPath $namespace): int
    {
        return ($this->namespaceCe[$namespace->toCanonical()] ?? new StringSet())->count();
    }

    public function getNamespaceCa(SymbolPath $namespace): int
    {
        return ($this->namespaceCa[$namespace->toCanonical()] ?? new StringSet())->count();
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
