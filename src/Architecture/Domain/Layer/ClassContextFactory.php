<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Domain\Layer;

use Qualimetrix\Core\Dependency\DependencyGraphInterface;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Builds {@see ClassContext} instances from collection-phase data for the
 * {@code attributes}, {@code implements} and {@code extends} membership
 * criteria (Phase 2 direction 1).
 *
 * The factory owns the per-run binding to the analysis dependency graph:
 *
 * 1. {@see LayerViolationRule::analyze()} calls {@see bindGraph()} at the
 *    start of every run, passing {@see \Qualimetrix\Core\Rule\AnalysisContext::$dependencyGraph}.
 * 2. {@see LayerRegistry::resolveAll()} calls {@see build()} for every class
 *    queried during evidence collection and edge resolution.
 * 3. After the rule run, the registry's cache holds layer matches keyed by
 *    {@see SymbolPath::toCanonical()}, and the factory's internal maps are
 *    rebuilt the next time {@see bindGraph()} is called.
 *
 * **Data source.** Attribute, interface and parent-class relationships are
 * already captured during the dependency-collection phase as
 * {@see DependencyType::Attribute}, {@see DependencyType::Implements} and
 * {@see DependencyType::Extends} edges (see
 * {@see \Qualimetrix\Analysis\Collection\Dependency\Handler\ClassLikeHandler}).
 * The factory walks the merged graph once to build child→parent maps and
 * services membership queries from them — no new collector, no AST traversal,
 * no worker-serialisation impact.
 *
 * **Transitive resolution.** {@see ClassContext::$parentClasses} carries the
 * full extends chain; {@see ClassContext::$interfaces} adds direct interfaces,
 * interfaces inherited from parent classes, and interfaces transitively
 * reached via interface-extends-interface edges (interfaces use
 * {@see DependencyType::Extends} for inheritance — same edge kind as classes,
 * disambiguated by walk start point). Vendor classes outside the analysed
 * project are NOT followed via reflection in Step B; their extends/implements
 * chains end at the project boundary. This matches the data the graph already
 * exposes and is sufficient for the documented test cases — reflection
 * fallback is a follow-up if vendor base-class matching turns out to be
 * required in practice.
 *
 * **No-graph mode.** Before {@see bindGraph()} is called (e.g. during config
 * load, or in the {@code debug:layer-assignment} command which runs without
 * analysis), {@see build()} returns a minimal context with empty attribute /
 * interface / parent lists. Only the {@code patterns} and {@code suffix}
 * criteria can fire in that mode — sufficient for Phase-1-shape configs and
 * the debug command's pattern-only inspection.
 */
final class ClassContextFactory
{
    private ?DependencyGraphInterface $graph = null;

    /**
     * Child FQN → list of direct parent-class / parent-interface FQNs.
     *
     * **Dual-purpose map.** {@see DependencyType::Extends} is emitted for both
     * {@code class extends Class} AND {@code interface extends Interface}
     * (see {@see \Qualimetrix\Analysis\Collection\Dependency\Handler\ClassLikeHandler}).
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
     * Memoised contexts keyed by class FQN. Repeated lookups for the same
     * class within one run share the transitive-walk result.
     *
     * @var array<string, ClassContext>
     */
    private array $contextCache = [];

    /**
     * Binds the factory to the analysis-run dependency graph. Resets all
     * internal caches so the next {@see build()} call rebuilds the lookup
     * maps. Passing {@code null} switches the factory back to no-graph mode.
     */
    public function bindGraph(?DependencyGraphInterface $graph): void
    {
        $this->graph = $graph;
        $this->extendsMap = null;
        $this->implementsMap = null;
        $this->attributesMap = null;
        $this->contextCache = [];
    }

    /**
     * Builds a {@see ClassContext} for the given symbol.
     *
     * For pure-namespace paths (no {@code type} segment) or empty FQNs returns
     * a minimal context whose only meaningful field is the FQN itself —
     * matches Phase-1 behaviour for namespace-level layer queries.
     */
    public function build(SymbolPath $class): ClassContext
    {
        $fqn = $this->fqnFor($class);
        if ($fqn === null) {
            return new ClassContext('', '');
        }

        if (isset($this->contextCache[$fqn])) {
            return $this->contextCache[$fqn];
        }

        $shortName = self::deriveShortName($fqn);

        if ($this->graph === null || $class->type === null || $class->type === '') {
            return $this->contextCache[$fqn] = new ClassContext($fqn, $shortName);
        }

        $this->ensureMapsBuilt();

        $attributes = $this->attributesMap[$fqn] ?? [];
        $parents = $this->collectTransitiveParents($fqn);
        $interfaces = $this->collectTransitiveInterfaces($fqn, $parents);

        return $this->contextCache[$fqn] = new ClassContext(
            $fqn,
            $shortName,
            $attributes,
            $interfaces,
            $parents,
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
            $sourceFqn = $this->fqnFor($dependency->source);
            $targetFqn = $this->fqnFor($dependency->target);
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
     * @return list<string>
     */
    private function collectTransitiveParents(string $fqn): array
    {
        \assert($this->extendsMap !== null);

        return self::bfsClosure($this->extendsMap[$fqn] ?? [], $this->extendsMap);
    }

    /**
     * Direct implements + interfaces inherited from parent classes +
     * transitive interface-extends-interface walks. Returned in BFS order
     * from the class outwards; the list is deduplicated.
     *
     * @param list<string> $parentClasses Already-collected transitive
     *                                    parent-class FQNs.
     *
     * @return list<string>
     */
    private function collectTransitiveInterfaces(string $fqn, array $parentClasses): array
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
        return self::bfsClosure($seedQueue, $this->extendsMap);
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
     * @param list<string> $seedQueue
     * @param array<string, list<string>> $adjacency
     *
     * @return list<string>
     */
    private static function bfsClosure(array $seedQueue, array $adjacency): array
    {
        $result = [];
        $seen = [];
        $queue = $seedQueue;

        while ($queue !== []) {
            $next = array_shift($queue);
            if (isset($seen[$next])) {
                continue;
            }
            $seen[$next] = true;
            $result[] = $next;

            foreach ($adjacency[$next] ?? [] as $neighbour) {
                if (!isset($seen[$neighbour])) {
                    $queue[] = $neighbour;
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
