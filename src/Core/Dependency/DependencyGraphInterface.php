<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Dependency;

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
     * @param SymbolPath $class Class-level SymbolPath
     *
     * @return array<Dependency> Dependencies where this class is the source
     */
    public function getClassDependencies(SymbolPath $class): array;

    /**
     * Returns all dependencies TO the given class (afferent dependencies).
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
     * @return array<Dependency>
     */
    public function getAllDependencies(): array;
}
