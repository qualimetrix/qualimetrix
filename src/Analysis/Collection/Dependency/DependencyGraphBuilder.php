<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection\Dependency;

use AiMessDetector\Core\Dependency\Dependency;
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
        $classes = StringSet::fromArray([]);
        $namespaces = StringSet::fromArray([]);

        // Index dependencies and collect unique classes/namespaces
        foreach ($dependencies as $dep) {
            // Index by source
            if (!isset($bySource[$dep->sourceClass])) {
                $bySource[$dep->sourceClass] = [];
            }
            $bySource[$dep->sourceClass][] = $dep;

            // Index by target
            if (!isset($byTarget[$dep->targetClass])) {
                $byTarget[$dep->targetClass] = [];
            }
            $byTarget[$dep->targetClass][] = $dep;

            // Collect unique classes
            $classes = $classes->add($dep->sourceClass);
            $classes = $classes->add($dep->targetClass);

            // Collect unique namespaces
            $sourceNs = $dep->getSourceNamespace();
            $targetNs = $dep->getTargetNamespace();

            if ($sourceNs !== '') {
                $namespaces = $namespaces->add($sourceNs);
            }
            if ($targetNs !== '') {
                $namespaces = $namespaces->add($targetNs);
            }
        }

        // Precompute namespace Ce/Ca
        $namespaceCe = $this->computeNamespaceCe($dependencies, $namespaces->toArray());
        $namespaceCa = $this->computeNamespaceCa($dependencies, $namespaces->toArray());

        return new DependencyGraph(
            $dependencies,
            $bySource,
            $byTarget,
            $classes->toArray(),
            $namespaces->toArray(),
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
     * @param array<string> $namespaces
     *
     * @return array<string, StringSet>
     */
    private function computeNamespaceCe(array $dependencies, array $namespaces): array
    {
        /** @var array<string, StringSet> $result */
        $result = [];

        // Initialize all namespaces with empty sets
        foreach ($namespaces as $ns) {
            $result[$ns] = new StringSet();
        }

        // For each dependency, if source is in namespace and target is outside,
        // add target to namespace's Ce
        foreach ($dependencies as $dep) {
            $sourceNs = $dep->getSourceNamespace();
            $targetNs = $dep->getTargetNamespace();

            // Skip if source namespace is empty (global namespace)
            if ($sourceNs === '') {
                continue;
            }

            // Skip if target is in same namespace (internal dependency)
            if ($sourceNs === $targetNs) {
                continue;
            }

            // Add target class to namespace's Ce
            $result[$sourceNs] = $result[$sourceNs]->add($dep->targetClass);
        }

        return $result;
    }

    /**
     * Computes Afferent Coupling (Ca) for each namespace.
     *
     * Ca = unique external classes that depend on classes in this namespace.
     *
     * @param array<Dependency> $dependencies
     * @param array<string> $namespaces
     *
     * @return array<string, StringSet>
     */
    private function computeNamespaceCa(array $dependencies, array $namespaces): array
    {
        /** @var array<string, StringSet> $result */
        $result = [];

        // Initialize all namespaces with empty sets
        foreach ($namespaces as $ns) {
            $result[$ns] = new StringSet();
        }

        // For each dependency, if target is in namespace and source is outside,
        // add source to namespace's Ca
        foreach ($dependencies as $dep) {
            $sourceNs = $dep->getSourceNamespace();
            $targetNs = $dep->getTargetNamespace();

            // Skip if target namespace is empty (global namespace)
            if ($targetNs === '') {
                continue;
            }

            // Skip if source is in same namespace (internal dependency)
            if ($sourceNs === $targetNs) {
                continue;
            }

            // Add source class to namespace's Ca
            $result[$targetNs] = $result[$targetNs]->add($dep->sourceClass);
        }

        return $result;
    }
}
