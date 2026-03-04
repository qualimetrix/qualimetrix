<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Dependency;

/**
 * Interface for querying dependency relationships between classes and namespaces.
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
     * @param string $className FQN of the class
     *
     * @return array<Dependency> Dependencies where this class is the source
     */
    public function getClassDependencies(string $className): array;

    /**
     * Returns all dependencies TO the given class (afferent dependencies).
     *
     * @param string $className FQN of the class
     *
     * @return array<Dependency> Dependencies where this class is the target
     */
    public function getClassDependents(string $className): array;

    /**
     * Returns Efferent Coupling for a class.
     *
     * Ce = count of unique external classes this class depends on.
     */
    public function getClassCe(string $className): int;

    /**
     * Returns Afferent Coupling for a class.
     *
     * Ca = count of unique external classes that depend on this class.
     */
    public function getClassCa(string $className): int;

    /**
     * Returns Efferent Coupling for a namespace.
     *
     * Ce = count of unique external classes that classes in this namespace depend on.
     * Only counts dependencies to classes outside this namespace.
     */
    public function getNamespaceCe(string $namespace): int;

    /**
     * Returns Afferent Coupling for a namespace.
     *
     * Ca = count of unique external classes that depend on classes in this namespace.
     * Only counts dependencies from classes outside this namespace.
     */
    public function getNamespaceCa(string $namespace): int;

    /**
     * Returns all unique class names in the graph.
     *
     * @return array<string> FQNs of all classes
     */
    public function getAllClasses(): array;

    /**
     * Returns all unique namespace names in the graph.
     *
     * @return array<string> Namespace names
     */
    public function getAllNamespaces(): array;

    /**
     * Returns all dependencies in the graph.
     *
     * @return array<Dependency>
     */
    public function getAllDependencies(): array;
}
