<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\Handler\ClassLikeHandler;
use Qualimetrix\Core\Symbol\PhpBuiltinClassHierarchy;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Builds {@see ClassContext} instances from collection-phase data for the
 * {@code attributes}, {@code implements} and {@code extends} membership
 * criteria.
 *
 * The factory owns the per-run binding to the analysis dependency graph, under
 * one invariant: **every reader of a context runs after {@see bindGraph()}**.
 * The invariant is stated rather than delegated to a list of today's readers,
 * because a list is what rotted last time — it named a rule that had stopped
 * binding and a pipeline that had stopped building, while a reader nobody had
 * listed was quietly reading unbound answers.
 *
 * {@see LayerCriteriaMatcher::refuseUnbackedCriteria()} enforces it where it
 * can be observed: a graph-backed criterion evaluated against an unbound
 * context throws instead of reporting a non-match. Binding itself rebuilds the
 * lookup maps and drops every cached context.
 *
 * **Data source.** Attribute, interface and parent-class relationships are
 * already captured during the dependency-collection phase as
 * {@see DependencyType::Attribute}, {@see DependencyType::Implements} and
 * {@see DependencyType::Extends} edges (see
 * {@see \Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\Handler\ClassLikeHandler}).
 * The factory walks the graph's declaration edges once to build child→parent
 * maps and services membership queries from them — no new collector, no AST
 * traversal, no worker-serialisation impact. It reads
 * {@see DependencyGraphInterface::getDeclarationDependencies()}, not the
 * coupling view: the coupling view leaves out edges to PHP's own classes, and
 * a class declaring `implements \JsonSerializable` would read as one that
 * does not.
 *
 * **Transitive resolution.** {@see ClassContext::$parentClasses} carries the
 * full extends chain; {@see ClassContext::$interfaces} adds direct interfaces,
 * interfaces inherited from parent classes, and interfaces transitively
 * reached via interface-extends-interface edges (interfaces use
 * {@see DependencyType::Extends} for inheritance — same edge kind as classes,
 * disambiguated by walk start point).
 *
 * **Where a chain ends, and what that is allowed to mean.** A class the run
 * did not analyse has no edges out of it in the graph. The edge INTO it was
 * recorded from the analysed child, so a criterion naming a DIRECT vendor
 * parent still matches; a criterion naming anything beyond that link cannot be
 * answered. The factory therefore reports where it stopped —
 * {@see ClassContext::$ancestryCuts} names every FQN the walks reached without
 * facts of their own — instead of handing back a truncated chain that
 * reads like a complete one. Answering that difference is
 * {@see CriterionOutcome}'s job; producing it is this class's.
 *
 * A class or interface PHP itself declares is not such a link: its supertypes
 * are PHP's own, answered by {@see PhpBuiltinClassHierarchy} — a static table,
 * so the answer does not depend on which PHP runs the analysis.
 *
 * An interface's `implements` edge is one PHP added: `Stringable` for an
 * interface declaring `__toString()`. The interface walk follows it; the
 * parent walk does not, so `extends: ['\Stringable']` does not see it.
 *
 * **No-graph mode.** Before {@see bindGraph()} is called (config load, and any
 * caller that builds its own registry without a run behind it), {@see build()}
 * returns a minimal context whose lists are empty and whose
 * {@see ClassContext::$graphBacked} is false. {@code patterns} and
 * {@code suffix} are answerable from the FQN and still fire; the three
 * graph-backed criteria refuse rather than report a non-match, which is the
 * difference between this mode and the bug it used to hide.
 */
final class ClassContextFactory
{
    private ?DependencyGraphInterface $graph = null;

    /**
     * Child FQN → list of direct parent-class / parent-interface FQNs.
     *
     * **Dual-purpose map.** {@see DependencyType::Extends} is emitted for both
     * {@code class extends Class} AND {@code interface extends Interface}
     * (see {@see \Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\Handler\ClassLikeHandler}).
     * Class-extends-interface and interface-extends-class are not valid PHP
     * grammar, so a walk seeded from a class FQN only ever encounters parent
     * classes, and a walk seeded from an interface FQN only ever encounters
     * parent interfaces. The map is therefore safe to share between the
     * parent-class walk in {@see build()} and the
     * {@see collectTransitiveInterfaces()} (interface chain) walks. A future
     * walk starting from a hybrid seed list MUST disambiguate explicitly.
     *
     * Built lazily on first {@see build()} after {@see bindGraph()}; cleared
     * when the graph is rebound.
     *
     * @var array<string, list<string>>|null
     */
    private ?array $extendsMap = null;

    /**
     * Class FQN → list of direct implemented interface FQNs.
     *
     * @var array<string, list<string>>|null
     */
    private ?array $implementsMap = null;

    /**
     * Class FQN → list of applied attribute FQNs (deduplicated, first
     * occurrence wins for ordering).
     *
     * @var array<string, list<string>>|null
     */
    private ?array $attributesMap = null;

    /**
     * Memoised contexts keyed by {@see SymbolPath::toCanonical()}. Repeated
     * lookups for the same symbol within one run share the transitive-walk
     * result.
     *
     * @var array<string, ClassContext>
     */
    private array $contextCache = [];

    /**
     * The declarations this run read, as far as the binding caller said.
     *
     * Only {@see \Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy::prepare()} knows the
     * set, and it is the one binding point a run goes through, so a run never
     * sees {@see AnalysedDeclarations::unknown()} — a registry assembled by
     * hand for a unit test does.
     */
    private AnalysedDeclarations $analysed;

    public function __construct()
    {
        $this->analysed = AnalysedDeclarations::unknown();
    }

    /**
     * Binds the factory to the analysis-run dependency graph. Resets all
     * internal caches so the next {@see build()} call rebuilds the lookup
     * maps. Passing {@code null} switches the factory back to no-graph mode.
     *
     * @param iterable<SymbolPath>|null $analysedClasses The declarations this
     *                                                   run analysed. Supplying
     *                                                   them is what lets a
     *                                                   context tell a chain
     *                                                   that ended from one
     *                                                   that was cut; omitting
     *                                                   them makes every answer
     *                                                   read as complete, as it
     *                                                   always did.
     */
    public function bindGraph(?DependencyGraphInterface $graph, ?iterable $analysedClasses = null): void
    {
        $this->graph = $graph;
        $this->extendsMap = null;
        $this->implementsMap = null;
        $this->attributesMap = null;
        $this->contextCache = [];
        $this->analysed = $analysedClasses === null
            ? AnalysedDeclarations::unknown()
            : AnalysedDeclarations::of($analysedClasses);
    }

    /**
     * Builds a {@see ClassContext} for the given symbol.
     *
     * For pure-namespace paths (no {@code type} segment) or empty FQNs returns
     * a minimal context whose only meaningful field is the FQN itself —
     * which is what a namespace-level layer query can be answered from.
     */
    public function build(SymbolPath $class): ClassContext
    {
        $fqn = $this->fqnFor($class);
        if ($fqn === null) {
            return new ClassContext('', '');
        }

        // Keyed by the whole path, not by the FQN: a class and the namespace
        // of the same name share an FQN, and whichever was asked for first
        // would otherwise answer for both.
        $cacheKey = $class->toCanonical();
        if (isset($this->contextCache[$cacheKey])) {
            return $this->contextCache[$cacheKey];
        }

        $shortName = self::deriveShortName($fqn);

        if ($this->graph === null) {
            return $this->contextCache[$cacheKey] = new ClassContext($fqn, $shortName, graphBacked: false);
        }

        if ($class->type === null || $class->type === '') {
            return $this->contextCache[$cacheKey] = new ClassContext($fqn, $shortName);
        }

        $this->ensureMapsBuilt();

        // The subject itself is the first step of the walk. When the run never
        // analysed it and PHP does not declare it, the chain is cut at the
        // class, not above it, and its attribute list is silence too — which
        // is why ClassContext derives `declarationAnalysed` from the parent-
        // chain cuts rather than carrying a separate flag. A class PHP declares
        // is answered from PHP, as it is anywhere else on a chain.
        $parentCuts = [];
        $interfaceCuts = [];
        $parentOf = PhpBuiltinClassHierarchy::extendsOf(...);

        $attributes = $this->attributesMap[$fqn] ?? PhpBuiltinClassHierarchy::attributesOf($fqn) ?? [];
        $parents = $this->bfsClosure($this->supertypesOf($fqn, $parentCuts, $parentOf), $parentCuts, $parentOf);
        $interfaces = $this->collectTransitiveInterfaces($fqn, $parents, $interfaceCuts);

        return $this->contextCache[$cacheKey] = new ClassContext(
            $fqn,
            $shortName,
            $attributes,
            $interfaces,
            $parents,
            ancestryCuts: ['parentChain' => array_keys($parentCuts), 'interfaces' => array_keys($interfaceCuts)],
        );
    }

    private function ensureMapsBuilt(): void
    {
        if ($this->extendsMap !== null) {
            return;
        }

        \assert($this->graph !== null);

        $extends = [];
        $implements = [];
        $attributes = [];

        foreach ($this->graph->getDeclarationDependencies() as $dependency) {
            // An anonymous class's own extends/implements/attribute is
            // recorded with the enclosing class as source (it has no
            // declaration identity of its own) — membership must not move
            // the enclosing class under rules written for the nested
            // anonymous class instead.
            if ($dependency->describesNestedAnonymousClass) {
                continue;
            }

            $sourceFqn = $this->fqnFor($dependency->sourceLogical());
            $targetFqn = $this->fqnFor($dependency->targetLogical());
            if ($sourceFqn === null || $targetFqn === null) {
                continue;
            }

            switch ($dependency->type) {
                case DependencyType::Extends:
                    $extends[$sourceFqn][] = $targetFqn;
                    break;
                case DependencyType::Implements:
                    $implements[$sourceFqn][] = $targetFqn;
                    break;
                case DependencyType::Attribute:
                    $attributes[$sourceFqn][] = $targetFqn;
                    break;
                default:
                    break;
            }
        }

        $this->extendsMap = self::dedupeListValues($extends);
        $this->implementsMap = self::dedupeListValues($implements);
        $this->attributesMap = self::dedupeListValues($attributes);
    }

    /**
     * Direct implements + interfaces inherited from parent classes +
     * transitive interface-extends-interface walks. Returned in BFS order
     * from the class outwards; the list is deduplicated.
     *
     * @param list<string> $parentClasses Already-collected transitive
     *                                    parent-class FQNs.
     * @param array<string, true> $unresolved Collects every interface the walk
     *                                        reached whose own declaration the
     *                                        run did not analyse.
     *
     * @return list<string>
     */
    private function collectTransitiveInterfaces(string $fqn, array $parentClasses, array &$unresolved): array
    {
        \assert($this->implementsMap !== null);
        \assert($this->extendsMap !== null);

        $seedQueue = $this->implementsMap[$fqn] ?? PhpBuiltinClassHierarchy::interfacesOf($fqn) ?? [];
        foreach ($parentClasses as $parent) {
            $declared = $this->implementsMap[$parent] ?? PhpBuiltinClassHierarchy::interfacesOf($parent) ?? [];
            foreach ($declared as $iface) {
                $seedQueue[] = $iface;
            }
        }

        // Interfaces extending other interfaces produce DependencyType::Extends
        // edges (see ClassLikeHandler::handleInterface). The shared extendsMap
        // is therefore the canonical source for interface inheritance too; the
        // implements map adds the one edge PHP gives an interface unwritten.
        return $this->bfsClosure($seedQueue, $unresolved, PhpBuiltinClassHierarchy::interfacesOf(...), $this->implementsMap);
    }

    /**
     * Walks the BFS transitive closure of {@code $seedQueue} through the
     * extends map, returning the discovery order with duplicates removed.
     *
     * A node with no adjacency entry ends the walk along that branch, and the
     * walk cannot tell from the map alone whether it ended because the node
     * declares nothing above it or because the node was never analysed. The
     * universe answers that, and the second case is recorded in
     * {@code $unresolved} rather than passed off as the first. A node PHP
     * declares is neither: its supertypes come from {@code $phpAbove}.
     *
     * @param list<string> $seedQueue
     * @param array<string, true> $unresolved
     * @param callable(string): (list<string>|null) $phpAbove
     * @param array<string, list<string>> $alsoAbove Edges that lead up besides
     *                                               the extends map — on the
     *                                               interface walk, the implements
     *                                               map, whose only edge out of an
     *                                               interface is the `Stringable`
     *                                               PHP adds
     *
     * @return list<string>
     */
    private function bfsClosure(array $seedQueue, array &$unresolved, callable $phpAbove, array $alsoAbove = []): array
    {
        $result = [];
        $seen = [];
        // Index cursor instead of array_shift — array_shift re-indexes the
        // entire backing array on every pop (O(n) per call). For deep parent /
        // interface chains this turned into hot O(n²) behaviour.
        $queue = $seedQueue;
        $cursor = 0;
        $tail = \count($queue);

        while ($cursor < $tail) {
            $next = $queue[$cursor++];
            if (isset($seen[$next])) {
                continue;
            }
            $seen[$next] = true;
            $result[] = $next;

            foreach ($this->supertypesOf($next, $unresolved, $phpAbove, $alsoAbove) as $neighbour) {
                if (!isset($seen[$neighbour])) {
                    $queue[] = $neighbour;
                    $tail++;
                }
            }
        }

        return $result;
    }

    /**
     * The next step up from one node: the run's own edges, PHP's declaration
     * of it, or — for a node the run did not read — nothing, recorded as a cut.
     *
     * @param array<string, true> $unresolved
     * @param callable(string): (list<string>|null) $phpAbove
     * @param array<string, list<string>> $alsoAbove
     *
     * @return list<string>
     */
    private function supertypesOf(string $fqn, array &$unresolved, callable $phpAbove, array $alsoAbove = []): array
    {
        \assert($this->extendsMap !== null);

        $also = $alsoAbove[$fqn] ?? [];
        $above = $this->extendsMap[$fqn] ?? $phpAbove($fqn);
        if ($above !== null) {
            return [...$above, ...$also];
        }

        // An edge out of the node was recorded, so the run read it.
        if ($also !== []) {
            return $also;
        }

        if (!$this->analysed->contains($fqn)) {
            $unresolved[$fqn] = true;
        }

        return [];
    }

    /**
     * `Namespace\Type`, the bare type, the bare namespace, or null when the
     * path names neither.
     */
    private function fqnFor(SymbolPath $class): ?string
    {
        $fqn = trim(($class->namespace ?? '') . '\\' . ($class->type ?? ''), '\\');

        return $fqn === '' ? null : $fqn;
    }

    private static function deriveShortName(string $fqn): string
    {
        $position = strrpos($fqn, '\\');

        return $position === false ? $fqn : substr($fqn, $position + 1);
    }

    /**
     * Deduplicates each per-source list while preserving first-occurrence
     * order. Same target referenced through multiple edges (e.g. two
     * #[Attr] occurrences on the same class) collapses into one entry.
     *
     * @param array<string, list<string>> $map
     *
     * @return array<string, list<string>>
     */
    private static function dedupeListValues(array $map): array
    {
        $result = [];
        foreach ($map as $key => $values) {
            $seen = [];
            $deduped = [];
            foreach ($values as $value) {
                if (isset($seen[$value])) {
                    continue;
                }
                $seen[$value] = true;
                $deduped[] = $value;
            }
            $result[$key] = $deduped;
        }

        return $result;
    }
}
