<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * In-memory implementation of DependencyGraphInterface.
 *
 * Provides efficient lookups via pre-built indexes using canonical string keys.
 * The public API accepts/returns SymbolPath, but internally uses toCanonical()
 * as hash map keys for performance.
 *
 * @qmx-threshold cohesion.lcom warning=6 error=6 -- Graph queries compose class coupling, namespace boundaries, logical inventory, ordered occurrences and declaration evidence; each independent view retains its own input.
 * @qmx-threshold health.cohesion 31.154195 -- Independent immutable observations or stateless declaration operations intentionally have no shared instance-field graph; this finite class boundary keeps a further cohesion loss visible.
 */
final class DependencyGraph implements DependencyGraphInterface
{
    private readonly ClassCouplings $classCouplings;

    private readonly LogicalGraphInventory $inventory;

    private readonly DeclarationEvidence $declarations;

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
     *
      * @qmx-threshold code-smell.long-parameter-list 11 -- Logical, declaration and namespace indexes are views of the same dependency graph; constructing them together prevents mismatched graph snapshots.
      * @qmx-threshold code-smell.constructor-overinjection 11 -- The complete immutable graph facts travel together through one graph lifecycle; the finite boundary keeps further growth visible.
     */
    public function __construct(
        private readonly array $dependencies,
        array $bySource,
        array $byTarget,
        array $classes,
        array $namespaces,
        private readonly NamespaceCouplings $namespaceCouplings,
        array $classCe,
        array $classCa,
        array $declarationDependencies,
        array $classLikeDeclarations,
    ) {
        $this->classCouplings = ClassCouplings::fromClassViews($bySource, $byTarget, $classCe, $classCa);
        $this->inventory = new LogicalGraphInventory($classes, $namespaces);
        $this->declarations = new DeclarationEvidence($declarationDependencies, $classLikeDeclarations);
    }

    /**
     * @param array<Dependency> $dependencies
     *
     * @return list<Dependency>
     */
    public static function declarationsAmong(array $dependencies): array
    {
        return DeclarationEvidence::declarationsAmong($dependencies);
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
        return $this->inventory->classes();
    }

    public function getAllNamespaces(): array
    {
        return $this->inventory->namespaces();
    }

    public function getAllDependencies(): array
    {
        return $this->dependencies;
    }

    public function getDeclarationDependencies(): array
    {
        return $this->declarations->dependencies();
    }

    public function getClassLikeDeclarations(): array
    {
        return $this->declarations->classLikeDeclarations();
    }
}
