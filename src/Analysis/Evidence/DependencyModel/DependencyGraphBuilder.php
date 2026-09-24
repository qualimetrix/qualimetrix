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

/**
 * Builds a DependencyGraph from a collection of dependencies.
 *
 * Constructs all indexes and precomputes namespace-level Ce/Ca metrics
 * for efficient coupling queries.
 *
 * Dependencies targeting PHP built-in classes are excluded from the coupling
 * views because coupling to stable standard library types does not contribute
 * to architectural risk measured by CBO. An `extends` edge to one stays in the
 * edge list, because DitGlobalCollector and NocCollector read inheritance from
 * it, but no coupling query counts it: not the per-class lists, not Ce/Ca, not
 * either namespace scope.
 *
 * The declaration view keeps every declaration edge, built-in target or not:
 * what a class declares is a fact about the class, and a layer criterion
 * naming `\JsonSerializable` has nothing else to read it from.
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
        $declarationDependencies = DependencyGraph::declarationsAmong($dependencies);
        $dependencies = $this->retainGraphDependencies($dependencies);
        $couplingDependencies = $this->couplingDependencies($dependencies);
        $indexes = $this->indexGraphInputs($dependencies, $couplingDependencies, $logicalClassUniverse);
        [$canonicalNamespaceMap, $parentNamespaces] = $this->expandNamespaceUniverse($indexes['leafNamespaces']);
        $ownCouplings = $this->computeNamespaceCouplings($couplingDependencies, $canonicalNamespaceMap);
        $rollupCouplings = $parentNamespaces === []
            ? $ownCouplings
            : $this->withParentNamespaceCouplings($couplingDependencies, $parentNamespaces, $ownCouplings);

        return new DependencyGraph(
            $dependencies,
            $indexes['bySource'],
            $indexes['byTarget'],
            array_values($indexes['classes']),
            array_values($canonicalNamespaceMap),
            NamespaceCouplings::fromScopes(
                $rollupCouplings['coupling.ce'],
                $rollupCouplings['coupling.ca'],
                $ownCouplings['coupling.ce'],
                $ownCouplings['coupling.ca'],
            ),
            self::distinctOtherEnds($indexes['bySource'], static fn(Dependency $dep): SymbolPath => $dep->targetLogical()),
            self::distinctOtherEnds($indexes['byTarget'], static fn(Dependency $dep): SymbolPath => $dep->sourceLogical()),
            $declarationDependencies,
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
     * The retained edges every coupling query counts: all of them but an
     * `extends` of a PHP class, which is kept only for inheritance readers.
     *
     * @param list<Dependency> $dependencies
     *
     * @return list<Dependency>
     */
    private function couplingDependencies(array $dependencies): array
    {
        return array_values(array_filter(
            $dependencies,
            fn(Dependency $dependency): bool => !$this->isPhpBuiltinClass($dependency->targetLogical()),
        ));
    }

    /**
     * Classes and namespaces come from every retained edge; the per-class
     * lists, which Ce/Ca and CBO are read from, from the coupling edges only.
     *
     * @param list<Dependency> $dependencies
     * @param list<Dependency> $couplingDependencies
     * @param iterable<LogicalClassPath> $logicalClassUniverse
     *
     * @return array{
     *     bySource: array<string, list<Dependency>>,
     *     byTarget: array<string, list<Dependency>>,
     *     classes: array<string, SymbolPath>,
     *     leafNamespaces: array<string, SymbolPath>
     * }
     */
    private function indexGraphInputs(array $dependencies, array $couplingDependencies, iterable $logicalClassUniverse): array
    {
        [$bySource, $byTarget] = $this->indexCouplingEdges($couplingDependencies);
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
     * @param list<Dependency> $couplingDependencies
     *
     * @return array{array<string, list<Dependency>>, array<string, list<Dependency>>}
     */
    private function indexCouplingEdges(array $couplingDependencies): array
    {
        $bySource = [];
        $byTarget = [];

        foreach ($couplingDependencies as $dep) {
            $bySource[$dep->sourceLogical()->toCanonical()][] = $dep;
            $byTarget[$dep->targetLogical()->toCanonical()][] = $dep;
        }

        return [$bySource, $byTarget];
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
     * @return array{'coupling.ce': array<string, StringSet>, 'coupling.ca': array<string, StringSet>}
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

        return ['coupling.ce' => $ce, 'coupling.ca' => $ca];
    }

    /**
     * Returns the namespace couplings with every parent namespace recomputed
     * over prefix-based boundary semantics.
     *
     * For a parent namespace P, a dependency is external if one side is inside P
     * (namespace equals P or starts with P\) and the other side is outside P.
     * Dependencies between child namespaces of the same parent are internal.
     *
     * The argument is returned changed rather than modified in place because a
     * parent namespace carries both scopes at once: this subtree rollup, and
     * the own-scope value it replaces, which the graph also publishes.
     *
     * @param array<Dependency> $dependencies
     * @param array<string, SymbolPath> $parentNamespaces raw namespace string => SymbolPath
     * @param array{'coupling.ce': array<string, StringSet>, 'coupling.ca': array<string, StringSet>} $ownCouplings
     *
     * @return array{'coupling.ce': array<string, StringSet>, 'coupling.ca': array<string, StringSet>}
     */
    private function withParentNamespaceCouplings(
        array $dependencies,
        array $parentNamespaces,
        array $ownCouplings,
    ): array {
        $namespaceCe = $ownCouplings['coupling.ce'];
        $namespaceCa = $ownCouplings['coupling.ca'];

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

        return ['coupling.ce' => $namespaceCe, 'coupling.ca' => $namespaceCa];
    }

    /**
     * Precomputes a class coupling count: for each key of an edge index, the
     * number of unique classes at the other end of its edges. Indexed by
     * source with the target as the other end this is Ce; indexed by target
     * with the source as the other end, Ca.
     *
     * @param array<string, array<Dependency>> $index Dependencies indexed by one end's canonical key
     * @param callable(Dependency): SymbolPath $otherEnd
     *
     * @return array<string, int>
     */
    private static function distinctOtherEnds(array $index, callable $otherEnd): array
    {
        $result = [];

        foreach ($index as $key => $deps) {
            $ends = [];
            foreach ($deps as $dep) {
                $ends[$otherEnd($dep)->toCanonical()] = true;
            }
            $result[$key] = \count($ends);
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
