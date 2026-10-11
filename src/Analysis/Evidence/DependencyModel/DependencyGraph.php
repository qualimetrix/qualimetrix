<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * In-memory implementation of DependencyGraphInterface.
 *
 * Provides efficient lookups via pre-built indexes using canonical string keys.
 * The public API accepts/returns SymbolPath, but internally uses toCanonical()
 * as hash map keys for performance.
 *
 * @qmx-threshold cohesion.lcom warning=8 error=8 -- Graph queries compose class coupling, namespace boundaries and ordered declaration evidence; splitting the remaining views transfers their lookup surface.
 *
 * @qmx-ignore health.cohesion -- Lookup methods expose independent indexes of one dependency graph.
 */
final class DependencyGraph implements DependencyGraphInterface
{
    private readonly ClassCouplings $classCouplings;

    /**
     * @param array<Dependency> $dependencies All dependencies
     * @param array<string, array<Dependency>> $bySource Dependencies indexed by source canonical key
     * @param array<string, array<Dependency>> $byTarget Dependencies indexed by target canonical key
     * @param array<SymbolPath> $classes All unique class SymbolPaths
     * @param array<SymbolPath> $namespaces All unique namespace SymbolPaths
     * @param array<string, int> $classCe Precomputed efferent coupling per class (canonical key -> count)
     * @param array<string, int> $classCa Precomputed afferent coupling per class (canonical key -> count)
     * @param list<Dependency> $declarationDependencies The declaration edges, including those to PHP's own
     *                                                  classes. Not derived from `$dependencies`: those have
     *                                                  already lost every such edge but `extends`
     * @param list<ClassLikeDeclaration> $classLikeDeclarations Named declaration facts in encounter order
     */
    public function __construct(
        private readonly array $dependencies,
        array $bySource,
        array $byTarget,
        private readonly array $classes,
        private readonly array $namespaces,
        private readonly NamespaceCouplings $namespaceCouplings,
        array $classCe,
        array $classCa,
        private readonly array $declarationDependencies,
        private readonly array $classLikeDeclarations,
    ) {
        $this->classCouplings = ClassCouplings::fromClassViews($bySource, $byTarget, $classCe, $classCa);
    }

    /**
     * @param array<Dependency> $dependencies
     *
     * @return list<Dependency>
     */
    public static function declarationsAmong(array $dependencies): array
    {
        return array_values(array_filter(
            $dependencies,
            static fn(Dependency $dependency): bool => match ($dependency->type) {
                DependencyType::Extends, DependencyType::Implements, DependencyType::TraitUse, DependencyType::Attribute => true,
                default => false,
            },
        ));
    }

    public function getClassDependencies(SymbolPath $class): array
    {
        return $this->classCouplings->dependencies($class);
    }

    public function getClassDependents(SymbolPath $class): array
    {
        return $this->classCouplings->dependents($class);
    }

    public function getClassCe(SymbolPath $class): int
    {
        return $this->classCouplings->ce($class);
    }

    public function getClassCa(SymbolPath $class): int
    {
        return $this->classCouplings->ca($class);
    }

    public function getNamespaceCe(SymbolPath $namespace): int
    {
        return $this->namespaceCouplings->subtreeCe($namespace);
    }

    public function getNamespaceCa(SymbolPath $namespace): int
    {
        return $this->namespaceCouplings->subtreeCa($namespace);
    }

    public function getNamespaceOwnCe(SymbolPath $namespace): int
    {
        return $this->namespaceCouplings->ownCe($namespace);
    }

    public function getNamespaceOwnCa(SymbolPath $namespace): int
    {
        return $this->namespaceCouplings->ownCa($namespace);
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

    public function getDeclarationDependencies(): array
    {
        return $this->declarationDependencies;
    }

    public function getClassLikeDeclarations(): array
    {
        return $this->classLikeDeclarations;
    }
}
