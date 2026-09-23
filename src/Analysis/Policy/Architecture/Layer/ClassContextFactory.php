<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\Handler\ClassLikeHandler;
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
 * The factory walks the merged graph once to build child→parent maps and
 * services membership queries from them — no new collector, no AST traversal,
 * no worker-serialisation impact.
 *
 * **Transitive resolution.** {@see ClassContext::$parentClasses} carries the
 * full extends chain; {@see ClassContext::$interfaces} adds direct interfaces,
 * interfaces inherited from parent classes, and interfaces transitively
 * reached via interface-extends-interface edges (interfaces use
 * {@see DependencyType::Extends} for inheritance — same edge kind as classes,
 * disambiguated by walk start point).
 *
 * **Where a chain ends, and what that is allowed to mean.** A class outside
 * the analysed set is not followed: nothing reads it by reflection, and the
 * graph carries no edges out of it. Its own declaration edges were recorded
 * from the analysed child, so a criterion naming a DIRECT vendor parent still
 * matches; a criterion naming anything beyond that link cannot be answered.
 * The factory therefore reports where it stopped —
 * {@see ClassContext::$unresolvedDeclarations} names every FQN the walk
 * reached without facts of its own — instead of handing back a truncated chain
 * that reads like a complete one. Answering that difference is
 * {@see CriterionOutcome}'s job; producing it is this class's.
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
     * {@see collectTransitiveParents()} (class chain) and the
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

        // The subject itself when the run never analysed it: the chain is cut
        // at the class, not above it, and its attribute list is silence too —
        // which is why ClassContext derives `declarationAnalysed` from this
        // list rather than carrying a separate flag.
        $unresolved = $this->analysed->contains($fqn) ? [] : [$fqn => true];

        $attributes = $this->attributesMap[$fqn] ?? [];
        $parents = $this->collectTransitiveParents($fqn, $unresolved);
        $interfaces = $this->collectTransitiveInterfaces($fqn, $parents, $unresolved);

        return $this->contextCache[$cacheKey] = new ClassContext(
            $fqn,
            $shortName,
            $attributes,
            $interfaces,
            $parents,
            unresolvedDeclarations: array_keys($unresolved),
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

        foreach ($this->graph->getAllDependencies() as $dependency) {
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
     * @param array<string, true> $unresolved Collects every FQN the walk
     *                                        reached whose own declaration the
     *                                        run did not analyse.
     *
     * @return list<string>
     */
    private function collectTransitiveParents(string $fqn, array &$unresolved): array
    {
        \assert($this->extendsMap !== null);

        return $this->bfsClosure($this->extendsMap[$fqn] ?? [], $this->extendsMap, $unresolved);
    }

    /**
     * Direct implements + interfaces inherited from parent classes +
     * transitive interface-extends-interface walks. Returned in BFS order
     * from the class outwards; the list is deduplicated.
     *
     * @param list<string> $parentClasses Already-collected transitive
     *                                    parent-class FQNs.
     * @param array<string, true> $unresolved See {@see collectTransitiveParents()}.
     *
     * @return list<string>
     */
    private function collectTransitiveInterfaces(string $fqn, array $parentClasses, array &$unresolved): array
    {
        \assert($this->implementsMap !== null);
        \assert($this->extendsMap !== null);

        $seedQueue = self::collectDirectInterfacesIncludingParents(
            $this->implementsMap,
            $fqn,
            $parentClasses,
        );

        // Interfaces extending other interfaces produce DependencyType::Extends
        // edges (see ClassLikeHandler::handleInterface). The shared extendsMap
        // is therefore the canonical source for interface inheritance too.
        return $this->bfsClosure($seedQueue, $this->extendsMap, $unresolved);
    }

    /**
     * @param array<string, list<string>> $implementsMap
     * @param list<string> $parentClasses
     *
     * @return list<string>
     */
    private static function collectDirectInterfacesIncludingParents(
        array $implementsMap,
        string $fqn,
        array $parentClasses,
    ): array {
        $seedQueue = $implementsMap[$fqn] ?? [];
        foreach ($parentClasses as $parent) {
            foreach ($implementsMap[$parent] ?? [] as $iface) {
                $seedQueue[] = $iface;
            }
        }

        return $seedQueue;
    }

    /**
     * Walks the BFS transitive closure of {@code $seedQueue} through
     * {@code $adjacency}, returning the discovery order with duplicates
     * removed.
     *
     * A node with no adjacency entry ends the walk along that branch, and the
     * walk cannot tell from the map alone whether it ended because the node
     * declares nothing above it or because the node was never analysed. The
     * universe answers that, and the second case is recorded in
     * {@code $unresolved} rather than passed off as the first.
     *
     * @param list<string> $seedQueue
     * @param array<string, list<string>> $adjacency
     * @param array<string, true> $unresolved
     *
     * @return list<string>
     */
    private function bfsClosure(array $seedQueue, array $adjacency, array &$unresolved): array
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

            if (!$this->analysed->contains($next)) {
                $unresolved[$next] = true;
            }

            foreach ($adjacency[$next] ?? [] as $neighbour) {
                if (!isset($seen[$neighbour])) {
                    $queue[] = $neighbour;
                    $tail++;
                }
            }
        }

        return $result;
    }

    private function fqnFor(SymbolPath $class): ?string
    {
        $namespace = $class->namespace;
        $type = $class->type;

        $hasNamespace = $namespace !== null && $namespace !== '';
        $hasType = $type !== null && $type !== '';

        if (!$hasNamespace && !$hasType) {
            return null;
        }

        if (!$hasNamespace) {
            return $type;
        }

        if (!$hasType) {
            return $namespace;
        }

        return $namespace . '\\' . $type;
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
