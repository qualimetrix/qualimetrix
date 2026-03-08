<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection\Dependency;

use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Util\StringSet;

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

            // Collect unique namespaces (deduplicate via array key)
            $sourceNs = $dep->source->namespace;
            $targetNs = $dep->target->namespace;

            if ($sourceNs !== null && !isset($namespaceMap[$sourceNs])) {
                $namespaceMap[$sourceNs] = SymbolPath::forNamespace($sourceNs);
            }
            if ($targetNs !== null && !isset($namespaceMap[$targetNs])) {
                $namespaceMap[$targetNs] = SymbolPath::forNamespace($targetNs);
            }
        }

        // Re-key namespaceMap by canonical path for downstream consumers
        $canonicalNamespaceMap = [];
        foreach ($namespaceMap as $nsPath) {
            $canonicalNamespaceMap[$nsPath->toCanonical()] = $nsPath;
        }

        // Precompute namespace Ce/Ca
        $namespaceCe = $this->computeNamespaceCe($dependencies, $canonicalNamespaceMap);
        $namespaceCa = $this->computeNamespaceCa($dependencies, $canonicalNamespaceMap);

        // Precompute class-level Ce/Ca (unique targets/sources per class)
        $classCe = $this->computeClassCe($bySource);
        $classCa = $this->computeClassCa($byTarget);

        return new DependencyGraph(
            $dependencies,
            $bySource,
            $byTarget,
            array_values($classMap),
            array_values($canonicalNamespaceMap),
            $namespaceCe,
            $namespaceCa,
            $classCe,
            $classCa,
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

        // Cache for SymbolPath::forNamespace()->toCanonical() calls
        /** @var array<string, string> $nsCanonicalCache */
        $nsCanonicalCache = [];

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
            $nsKey = $nsCanonicalCache[$sourceNs] ??= SymbolPath::forNamespace($sourceNs)->toCanonical();
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

        // Cache for SymbolPath::forNamespace()->toCanonical() calls
        /** @var array<string, string> $nsCanonicalCache */
        $nsCanonicalCache = [];

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
            $nsKey = $nsCanonicalCache[$targetNs] ??= SymbolPath::forNamespace($targetNs)->toCanonical();
            $result[$nsKey] = $result[$nsKey]->add($dep->source->toCanonical());
        }

        return $result;
    }

    /**
     * Precomputes Efferent Coupling (Ce) for each class.
     *
     * Ce = count of unique classes this class depends on.
     *
     * @param array<string, array<Dependency>> $bySource Dependencies indexed by source canonical key
     *
     * @return array<string, int>
     */
    private function computeClassCe(array $bySource): array
    {
        $result = [];

        foreach ($bySource as $sourceKey => $deps) {
            $targets = [];
            foreach ($deps as $dep) {
                $targets[$dep->target->toCanonical()] = true;
            }
            $result[$sourceKey] = \count($targets);
        }

        return $result;
    }

    /**
     * Precomputes Afferent Coupling (Ca) for each class.
     *
     * Ca = count of unique classes that depend on this class.
     *
     * @param array<string, array<Dependency>> $byTarget Dependencies indexed by target canonical key
     *
     * @return array<string, int>
     */
    private function computeClassCa(array $byTarget): array
    {
        $result = [];

        foreach ($byTarget as $targetKey => $deps) {
            $sources = [];
            foreach ($deps as $dep) {
                $sources[$dep->source->toCanonical()] = true;
            }
            $result[$targetKey] = \count($sources);
        }

        return $result;
    }
}
