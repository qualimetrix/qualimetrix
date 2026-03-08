<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection\Dependency;

use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Dependency\DependencyGraphInterface;
use AiMessDetector\Core\Util\StringSet;
use AiMessDetector\Core\Violation\SymbolPath;

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
        $deps = $this->bySource[$class->toCanonical()] ?? [];
        $targets = StringSet::fromArray([]);

        foreach ($deps as $dep) {
            $targets = $targets->add($dep->target->toCanonical());
        }

        return $targets->count();
    }

    public function getClassCa(SymbolPath $class): int
    {
        $deps = $this->byTarget[$class->toCanonical()] ?? [];
        $sources = StringSet::fromArray([]);

        foreach ($deps as $dep) {
            $sources = $sources->add($dep->source->toCanonical());
        }

        return $sources->count();
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
