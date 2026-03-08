<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection\Dependency;

use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Util\StringSet;
use AiMessDetector\Core\Violation\SymbolPath;

/**
 * Builds a DependencyGraph from a collection of dependencies.
 *
 * Constructs all indexes and precomputes namespace-level Ce/Ca metrics
 * for efficient coupling queries.
 */
final class DependencyGraphBuilder
{
    /**
     * Builds a dependency graph from a collection of dependencies.
     *
     * @param array<Dependency> $dependencies
     */
    public function build(array $dependencies): DependencyGraph
    {
        $bySource = [];
        $byTarget = [];
        /** @var array<string, SymbolPath> $classMap */
        $classMap = [];
        /** @var array<string, SymbolPath> $namespaceMap */
        $namespaceMap = [];

        // Index dependencies and collect unique classes/namespaces
        foreach ($dependencies as $dep) {
            $sourceKey = $dep->source->toCanonical();
            $targetKey = $dep->target->toCanonical();

            // Index by source
            if (!isset($bySource[$sourceKey])) {
                $bySource[$sourceKey] = [];
            }
            $bySource[$sourceKey][] = $dep;

            // Index by target
            if (!isset($byTarget[$targetKey])) {
                $byTarget[$targetKey] = [];
            }
            $byTarget[$targetKey][] = $dep;

            // Collect unique classes
            $classMap[$sourceKey] = $dep->source;
            $classMap[$targetKey] = $dep->target;

            // Collect unique namespaces
            $sourceNs = $dep->source->namespace;
            $targetNs = $dep->target->namespace;

            if ($sourceNs !== null) {
                $nsPath = SymbolPath::forNamespace($sourceNs);
                $namespaceMap[$nsPath->toCanonical()] = $nsPath;
            }
            if ($targetNs !== null) {
                $nsPath = SymbolPath::forNamespace($targetNs);
                $namespaceMap[$nsPath->toCanonical()] = $nsPath;
            }
        }

        // Precompute namespace Ce/Ca
        $namespaceCe = $this->computeNamespaceCe($dependencies, $namespaceMap);
        $namespaceCa = $this->computeNamespaceCa($dependencies, $namespaceMap);

        return new DependencyGraph(
            $dependencies,
            $bySource,
            $byTarget,
            array_values($classMap),
            array_values($namespaceMap),
            $namespaceCe,
            $namespaceCa,
        );
    }

    /**
     * Computes Efferent Coupling (Ce) for each namespace.
     *
     * Ce = unique external classes that classes in this namespace depend on.
     *
     * @param array<Dependency> $dependencies
     * @param array<string, SymbolPath> $namespaceMap
     *
     * @return array<string, StringSet>
     */
    private function computeNamespaceCe(array $dependencies, array $namespaceMap): array
    {
        /** @var array<string, StringSet> $result */
        $result = [];

        // Initialize all namespaces with empty sets
        foreach ($namespaceMap as $canonicalKey => $nsPath) {
            $result[$canonicalKey] = new StringSet();
        }

        // For each dependency, if source is in namespace and target is outside,
        // add target to namespace's Ce
        foreach ($dependencies as $dep) {
            $sourceNs = $dep->source->namespace;
            $targetNs = $dep->target->namespace;

            // Skip file-level symbols (namespace is null only for file-level SymbolPaths)
            if ($sourceNs === null) {
                continue;
            }

            // Skip if target is in same namespace (internal dependency)
            if ($sourceNs === $targetNs) {
                continue;
            }

            // Add target class to namespace's Ce
            $nsKey = SymbolPath::forNamespace($sourceNs)->toCanonical();
            $result[$nsKey] = $result[$nsKey]->add($dep->target->toCanonical());
        }

        return $result;
    }

    /**
     * Computes Afferent Coupling (Ca) for each namespace.
     *
     * Ca = unique external classes that depend on classes in this namespace.
     *
     * @param array<Dependency> $dependencies
     * @param array<string, SymbolPath> $namespaceMap
     *
     * @return array<string, StringSet>
     */
    private function computeNamespaceCa(array $dependencies, array $namespaceMap): array
    {
        /** @var array<string, StringSet> $result */
        $result = [];

        // Initialize all namespaces with empty sets
        foreach ($namespaceMap as $canonicalKey => $nsPath) {
            $result[$canonicalKey] = new StringSet();
        }

        // For each dependency, if target is in namespace and source is outside,
        // add source to namespace's Ca
        foreach ($dependencies as $dep) {
            $sourceNs = $dep->source->namespace;
            $targetNs = $dep->target->namespace;

            // Skip file-level symbols (namespace is null only for file-level SymbolPaths)
            if ($targetNs === null) {
                continue;
            }

            // Skip if source is in same namespace (internal dependency)
            if ($sourceNs === $targetNs) {
                continue;
            }

            // Add source class to namespace's Ca
            $nsKey = SymbolPath::forNamespace($targetNs)->toCanonical();
            $result[$nsKey] = $result[$nsKey]->add($dep->source->toCanonical());
        }

        return $result;
    }
}
