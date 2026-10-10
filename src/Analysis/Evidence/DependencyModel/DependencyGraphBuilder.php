<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuild;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ExternalClassSpellingInterface;
use Qualimetrix\Core\Symbol\PhpBuiltinClassRegistry;
use Qualimetrix\Core\Symbol\SymbolPath;
use Traversable;

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
    public function __construct(private readonly ExternalClassSpellingInterface $externalClassSpelling) {}

    /**
     * Builds a dependency graph from a collection of dependencies.
     *
     * @param list<Dependency> $dependencies
     * @param iterable<ClassLikeDeclaration> $classLikeDeclarations
     */
    public function build(array $dependencies, iterable $classLikeDeclarations): DependencyGraphBuild
    {
        $classLikeDeclarations = $classLikeDeclarations instanceof Traversable
            ? iterator_to_array($classLikeDeclarations, false)
            : array_values($classLikeDeclarations);
        $canonical = (new DependencyIdentityCanonicalizer($this->externalClassSpelling))->canonicalize(
            $dependencies,
            $classLikeDeclarations,
        );
        $dependencies = $canonical->dependencies;
        $classLikeDeclarations = $canonical->declarations;
        $declarationDependencies = DependencyGraph::declarationsAmong($dependencies);
        $dependencies = $this->retainGraphDependencies($dependencies);
        $couplingDependencies = $this->couplingDependencies($dependencies);
        $indexes = $this->indexGraphInputs($dependencies, $couplingDependencies, $classLikeDeclarations);
        [$namespaces, $namespaceCouplings] = (new NamespaceCouplingBuilder())->build(
            $couplingDependencies,
            $indexes['leafNamespaces'],
        );

        $graph = new DependencyGraph(
            $dependencies,
            $indexes['bySource'],
            $indexes['byTarget'],
            array_values($indexes['classes']),
            $namespaces,
            $namespaceCouplings,
            self::distinctOtherEnds($indexes['bySource'], static fn(Dependency $dep): SymbolPath => $dep->targetLogical()),
            self::distinctOtherEnds($indexes['byTarget'], static fn(Dependency $dep): SymbolPath => $dep->sourceLogical()),
            $declarationDependencies,
            $classLikeDeclarations,
        );

        return new DependencyGraphBuild($graph, $canonical->mixedSpellings);
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
            fn(Dependency $dependency): bool => $dependency->sourceLogical()->toCanonical() !== $dependency->targetLogical()->toCanonical()
                && ($dependency->type === DependencyType::Extends || !$this->isPhpBuiltinClass($dependency->targetLogical())),
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
     * @param list<ClassLikeDeclaration> $classLikeDeclarations
     *
     * @return array{
     *     bySource: array<string, list<Dependency>>,
     *     byTarget: array<string, list<Dependency>>,
     *     classes: array<string, SymbolPath>,
     *     leafNamespaces: array<string, SymbolPath>
     * }
     */
    private function indexGraphInputs(array $dependencies, array $couplingDependencies, array $classLikeDeclarations): array
    {
        [$bySource, $byTarget] = $this->indexCouplingEdges($couplingDependencies);
        /** @var array<string, SymbolPath> $classMap */
        $classMap = [];
        /** @var array<string, SymbolPath> $namespaceMap */
        $namespaceMap = [];

        foreach ($classLikeDeclarations as $declaration) {
            $classPath = $declaration->logical->symbolPath;
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
