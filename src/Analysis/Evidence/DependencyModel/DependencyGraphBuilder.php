<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\PhpBuiltinClassRegistry;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Util\StringSet;

/**
 * Builds a DependencyGraph from a collection of dependencies.
 *
 * Constructs all indexes and precomputes namespace-level Ce/Ca metrics
 * for efficient coupling queries.
 *
 * Dependencies targeting PHP built-in classes are excluded from the graph
 * because coupling to stable standard library types does not contribute to
 * architectural risk measured by CBO. Only `extends` edges are preserved
 * (needed by DitGlobalCollector and NocCollector for inheritance metrics).
 */
final class DependencyGraphBuilder implements DependencyGraphBuilderInterface
{
    /**
     * Builds a dependency graph from a collection of dependencies.
     *
     * @param list<Dependency> $dependencies
     * @param iterable<LogicalClassPath> $logicalClassUniverse
     */
    public function build(array $dependencies, iterable $logicalClassUniverse): DependencyGraphInterface
    {
        $dependencies = $this->retainGraphDependencies($dependencies);
        $indexes = $this->indexGraphInputs($dependencies, $logicalClassUniverse);
        [$canonicalNamespaceMap, $parentNamespaces] = $this->expandNamespaceUniverse($indexes['leafNamespaces']);
        $namespaceCouplings = $this->computeNamespaceCouplings($dependencies, $canonicalNamespaceMap);

        if ($parentNamespaces !== []) {
            $this->computeParentNamespaceCouplings(
                $dependencies,
                $parentNamespaces,
                $namespaceCouplings['ce'],
                $namespaceCouplings['ca'],
            );
        }

        return new DependencyGraph(
            $dependencies,
            $indexes['bySource'],
            $indexes['byTarget'],
            array_values($indexes['classes']),
            array_values($canonicalNamespaceMap),
            $namespaceCouplings['ce'],
            $namespaceCouplings['ca'],
            $this->computeClassCe($indexes['bySource']),
            $this->computeClassCa($indexes['byTarget']),
        );
    }

    /**
     * @param array<Dependency> $dependencies
     *
     * @return list<Dependency>
     */
    private function retainGraphDependencies(array $dependencies): array
    {
        return array_values(array_filter(
            $dependencies,
            fn(Dependency $dependency): bool => $dependency->type === DependencyType::Extends
                || !$this->isPhpBuiltinClass($dependency->targetLogical()),
        ));
    }

    /**
     * @param list<Dependency> $dependencies
     * @param iterable<LogicalClassPath> $logicalClassUniverse
     *
     * @return array{
     *     bySource: array<string, list<Dependency>>,
     *     byTarget: array<string, list<Dependency>>,
     *     classes: array<string, SymbolPath>,
     *     leafNamespaces: array<string, SymbolPath>
     * }
     */
    private function indexGraphInputs(array $dependencies, iterable $logicalClassUniverse): array
    {
        $bySource = [];
        $byTarget = [];
        /** @var array<string, SymbolPath> $classMap */
        $classMap = [];
        /** @var array<string, SymbolPath> $namespaceMap */
        $namespaceMap = [];

        foreach ($logicalClassUniverse as $logicalClass) {
            $classPath = $logicalClass->symbolPath;
            $classMap[$classPath->toCanonical()] = $classPath;
            $namespace = $classPath->namespace;
            if ($namespace !== null && !isset($namespaceMap[$namespace])) {
                $namespaceMap[$namespace] = SymbolPath::forNamespace($namespace);
            }
        }

        foreach ($dependencies as $dep) {
            $source = $dep->sourceLogical();
            $target = $dep->targetLogical();
            $sourceKey = $source->toCanonical();
            $targetKey = $target->toCanonical();

            $bySource[$sourceKey][] = $dep;

            $byTarget[$targetKey][] = $dep;

            // Collect unique classes
            $classMap[$sourceKey] = $source;
            $classMap[$targetKey] = $target;

            // Collect unique namespaces (deduplicate via array key)
            $sourceNs = $source->namespace;
            $targetNs = $target->namespace;

            if ($sourceNs !== null && !isset($namespaceMap[$sourceNs])) {
                $namespaceMap[$sourceNs] = SymbolPath::forNamespace($sourceNs);
            }
            if ($targetNs !== null && !isset($namespaceMap[$targetNs])) {
                $namespaceMap[$targetNs] = SymbolPath::forNamespace($targetNs);
            }
        }

        return [
            'bySource' => $bySource,
            'byTarget' => $byTarget,
            'classes' => $classMap,
            'leafNamespaces' => $namespaceMap,
        ];
    }

    /**
     * @param array<string, SymbolPath> $leafNamespaces
     *
     * @return array{array<string, SymbolPath>, array<string, SymbolPath>}
     */
    private function expandNamespaceUniverse(array $leafNamespaces): array
    {
        $canonicalNamespaceMap = [];
        foreach ($leafNamespaces as $nsPath) {
            $canonicalNamespaceMap[$nsPath->toCanonical()] = $nsPath;
        }

        $parentNamespaces = [];
        foreach (array_keys($leafNamespaces) as $namespace) {
            $parentNamespace = $namespace;

            while (($separator = strrpos($parentNamespace, '\\')) !== false) {
                $parentNamespace = substr($parentNamespace, 0, $separator);
                $parentNamespaces[$parentNamespace] ??= SymbolPath::forNamespace($parentNamespace);
            }
        }
        foreach ($parentNamespaces as $nsPath) {
            $canonicalNamespaceMap[$nsPath->toCanonical()] = $nsPath;
        }

        return [$canonicalNamespaceMap, $parentNamespaces];
    }

    /**
     * @param list<Dependency> $dependencies
     * @param array<string, SymbolPath> $namespaceMap
     *
     * @return array{ce: array<string, StringSet>, ca: array<string, StringSet>}
     */
    private function computeNamespaceCouplings(array $dependencies, array $namespaceMap): array
    {
        $ce = [];
        $ca = [];

        foreach ($namespaceMap as $canonicalKey => $nsPath) {
            $ce[$canonicalKey] = new StringSet();
            $ca[$canonicalKey] = new StringSet();
        }

        /** @var array<string, string> $nsCanonicalCache */
        $nsCanonicalCache = [];

        foreach ($dependencies as $dep) {
            $source = $dep->sourceLogical();
            $target = $dep->targetLogical();
            $sourceNs = $source->namespace;
            $targetNs = $target->namespace;

            if ($sourceNs === $targetNs) {
                continue;
            }

            if ($sourceNs !== null) {
                $sourceKey = $nsCanonicalCache[$sourceNs] ??= SymbolPath::forNamespace($sourceNs)->toCanonical();
                $ce[$sourceKey] = $ce[$sourceKey]->add($target->toCanonical());
            }

            if ($targetNs !== null) {
                $targetKey = $nsCanonicalCache[$targetNs] ??= SymbolPath::forNamespace($targetNs)->toCanonical();
                $ca[$targetKey] = $ca[$targetKey]->add($source->toCanonical());
            }
        }

        return ['ce' => $ce, 'ca' => $ca];
    }

    /**
     * Computes Ce/Ca for parent namespaces using prefix-based boundary semantics.
     *
     * For a parent namespace P, a dependency is external if one side is inside P
     * (namespace equals P or starts with P\) and the other side is outside P.
     * Dependencies between child namespaces of the same parent are internal.
     *
     * @param array<Dependency> $dependencies
     * @param array<string, SymbolPath> $parentNamespaces raw namespace string => SymbolPath
     * @param array<string, StringSet> $namespaceCe modified in place
     * @param array<string, StringSet> $namespaceCa modified in place
     */
    private function computeParentNamespaceCouplings(
        array $dependencies,
        array $parentNamespaces,
        array &$namespaceCe,
        array &$namespaceCa,
    ): void {
        // Build prefix list: "App\Service" => "App\Service\"
        $parentPrefixes = [];
        $parentCanonicals = [];

        foreach ($parentNamespaces as $ns => $nsPath) {
            $canonical = $nsPath->toCanonical();
            $parentPrefixes[$ns] = $ns . '\\';
            $parentCanonicals[$ns] = $canonical;
            $namespaceCe[$canonical] = new StringSet();
            $namespaceCa[$canonical] = new StringSet();
        }

        foreach ($dependencies as $dep) {
            $source = $dep->sourceLogical();
            $target = $dep->targetLogical();
            $sourceNs = $source->namespace;
            $targetNs = $target->namespace;

            if ($sourceNs === null || $targetNs === null) {
                continue;
            }

            // Same leaf namespace — internal for ALL ancestors, skip
            if ($sourceNs === $targetNs) {
                continue;
            }

            foreach ($parentPrefixes as $parentNs => $prefix) {
                $sourceInside = $sourceNs === $parentNs || str_starts_with($sourceNs, $prefix);
                $targetInside = $targetNs === $parentNs || str_starts_with($targetNs, $prefix);

                // Both inside or both outside — not a boundary crossing for this parent
                if ($sourceInside === $targetInside) {
                    continue;
                }

                $canonical = $parentCanonicals[$parentNs];

                if ($sourceInside) {
                    // Efferent: source inside parent, target outside
                    $namespaceCe[$canonical] = $namespaceCe[$canonical]->add($target->toCanonical());
                } else {
                    // Afferent: target inside parent, source outside
                    $namespaceCa[$canonical] = $namespaceCa[$canonical]->add($source->toCanonical());
                }
            }
        }
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
                $targets[$dep->targetLogical()->toCanonical()] = true;
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
                $sources[$dep->sourceLogical()->toCanonical()] = true;
            }
            $result[$targetKey] = \count($sources);
        }

        return $result;
    }

    /**
     * Checks whether a SymbolPath points to a PHP built-in class or interface.
     */
    private function isPhpBuiltinClass(SymbolPath $target): bool
    {
        $className = $target->type;

        if ($className === null || $className === '') {
            return false;
        }

        $namespace = $target->namespace;

        // Build FQN for lookup: 'Exception' or 'Random\Randomizer'
        $fqn = ($namespace !== null && $namespace !== '')
            ? $namespace . '\\' . $className
            : $className;

        return PhpBuiltinClassRegistry::isBuiltin($fqn);
    }
}
