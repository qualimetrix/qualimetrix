<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Contract;

use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Interface for querying dependency relationships between classes and namespaces.
 *
 * This interface works with class-level and namespace-level SymbolPath only.
 *
 * The graph provides efficient lookups for coupling metrics:
 * - Ca (Afferent Coupling): incoming dependencies
 * - Ce (Efferent Coupling): outgoing dependencies
 * - I (Instability): Ce / (Ca + Ce)
 */
interface DependencyGraphInterface
{
    /**
     * Returns all dependencies FROM the given class (efferent dependencies).
     *
     * These are the edges coupling is counted from: an `extends` of a class
     * PHP itself declares is not among them, though {@see getAllDependencies()}
     * keeps it.
     *
     * @param SymbolPath $class Class-level SymbolPath
     *
     * @return array<Dependency> Dependencies where this class is the source
     */
    public function getClassDependencies(SymbolPath $class): array;

    /**
     * Returns all dependencies TO the given class (afferent dependencies).
     *
     * Coupling edges only, as for {@see getClassDependencies()}.
     *
     * @param SymbolPath $class Class-level SymbolPath
     *
     * @return array<Dependency> Dependencies where this class is the target
     */
    public function getClassDependents(SymbolPath $class): array;

    /**
     * Returns Efferent Coupling for a class.
     *
     * Ce = count of unique external classes this class depends on.
     */
    public function getClassCe(SymbolPath $class): int;

    /**
     * Returns Afferent Coupling for a class.
     *
     * Ca = count of unique external classes that depend on this class.
     */
    public function getClassCa(SymbolPath $class): int;

    /**
     * Returns Efferent Coupling for a namespace.
     *
     * Ce = count of unique external classes that classes in this namespace depend on.
     * Only counts dependencies to classes outside this namespace.
     */
    public function getNamespaceCe(SymbolPath $namespace): int;

    /**
     * Returns Afferent Coupling for a namespace.
     *
     * Ca = count of unique external classes that depend on classes in this namespace.
     * Only counts dependencies from classes outside this namespace.
     */
    public function getNamespaceCa(SymbolPath $namespace): int;

    /**
     * Returns Efferent Coupling (Ce) for the declarations of exactly this
     * namespace, ignoring the ones its sub-namespaces hold.
     *
     * For a namespace with no sub-namespaces this equals {@see getNamespaceCe()}.
     * For one that also contains sub-namespaces the two differ: the subtree
     * rollup treats a dependency on a sub-namespace as internal, the own scope
     * counts it as a crossing.
     */
    public function getNamespaceOwnCe(SymbolPath $namespace): int;

    /**
     * Returns Afferent Coupling (Ca) for the declarations of exactly this
     * namespace, ignoring the ones its sub-namespaces hold.
     *
     * The own-scope counterpart of {@see getNamespaceCa()}; see
     * {@see getNamespaceOwnCe()} for how the two scopes differ.
     */
    public function getNamespaceOwnCa(SymbolPath $namespace): int;

    /**
     * Returns all unique classes in the graph.
     *
     * @return array<SymbolPath> Class-level SymbolPaths
     */
    public function getAllClasses(): array;

    /**
     * Returns all unique namespaces in the graph.
     *
     * @return array<SymbolPath> Namespace-level SymbolPaths
     */
    public function getAllNamespaces(): array;

    /**
     * Returns all dependencies in the graph.
     *
     * Besides every coupling edge, this list keeps the `extends` edges to a
     * class PHP itself declares, which inheritance readers (DIT, NOC) need and
     * no coupling number counts.
     *
     * @return array<Dependency>
     */
    public function getAllDependencies(): array;

    /**
     * Returns every edge that states what a declaration is: its
     * {@see DependencyType::Extends}, {@see DependencyType::Implements},
     * {@see DependencyType::TraitUse} and {@see DependencyType::Attribute}
     * edges, in encounter order.
     *
     * It keeps the `implements`, `trait_use` and attribute edges whose target
     * is a class PHP itself declares, which every other query here leaves out
     * ({@see getAllDependencies()} keeps only `extends` of those, and no
     * coupling query keeps even that). Those edges count toward no coupling
     * view — not the dependency lists, the class set, Ce/Ca, nor either
     * namespace scope — but a reader asking what a class declares needs them:
     * without them a class declaring `implements \JsonSerializable` reads as
     * one that does not.
     *
     * @return list<Dependency>
     */
    public function getDeclarationDependencies(): array;
}
