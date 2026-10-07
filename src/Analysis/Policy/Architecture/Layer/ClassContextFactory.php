<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

use Closure;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\Handler\ClassLikeHandler;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ExternalSupertypes;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ExternalSupertypeSourceInterface;
use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Core\Symbol\PhpBuiltinClassHierarchy;
use Qualimetrix\Core\Symbol\PhpBuiltinClassRegistry;
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
 * reached via interface-extends-interface edges. For an interface, the
 * interfaces it extends are among its interfaces, as `getInterfaceNames()`
 * reports them: `implements:` sees the parent an interface names, not only
 * what is above it. An analysed interface is told from a class by the edges it
 * declares ({@see \Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency::$interfaceExtends});
 * one extending nothing has no parent to add, and a PHP interface's table
 * entry already lists what it extends.
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
 *
 * @qmx-threshold coupling.instability warning=0.81 -- Ca=2, Ce=8 is exactly 0.800 against an
 *                inclusive 0.800 ceiling. The eighth efferent edge is `PhpBuiltinClassRegistry`, which
 *                gives a PHP class the one spelling layer criteria are compared in; it is a static
 *                Core table nothing here can make less stable, and moving the call behind another
 *                class of this capability would only trade it for an edge to that class. 0.81 still
 *                reports the next efferent edge (Ce=9, 0.818).
 */
final class ClassContextFactory
{
    private const int MAX_INHERITANCE_DEPTH = 256;

    private ?DependencyGraphInterface $graph = null;

    private ?NameSpellingIndex $nameSpellings = null;

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
     * {@see collectTransitiveInterfaces()} walk, which is seeded with a
     * subject's parents only when {@see $interfaceSources} says the subject is
     * an interface. Any other walk mixing the two seeds MUST disambiguate the
     * same way.
     *
     * Built lazily on first {@see build()} after {@see bindGraph()}; cleared
     * when the graph is rebound.
     *
     * @var array<string, list<string>>|null
     */
    private ?array $extendsMap = null;

    /**
     * FQNs of the analysed interfaces that extend something — the sources of
     * the {@see DependencyType::Extends} edges an interface declares.
     *
     * @var array<string, true>|null
     */
    private ?array $interfaceSources = null;

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

    /** @var array<string, list<string>>|null */
    private ?array $memberAttributesMap = null;

    /** @var array<string, list<string>>|null */
    private ?array $traitUseMap = null;

    /** @var array<string, ClassLikeDeclaration>|null */
    private ?array $declarations = null;

    /** @var array<string, ExternalSupertypes> */
    private array $externalFacts = [];

    /** @var array<string, true> */
    private array $externalRelationsLoaded = [];

    /** @var array<string, bool|null> */
    private array $implicitStringable = [];

    /** @var array<string, true> */
    private array $implicitStringableDoubts = [];

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

    /** @var (Closure(string): bool)|null see {@see KnownTypes} */
    private ?Closure $installDeclares = null;

    private ?ExternalSupertypeSourceInterface $externalSupertypes = null;

    public function __construct()
    {
        $this->analysed = AnalysedDeclarations::unknown();
    }

    public function bindExternalSupertypeSource(?ExternalSupertypeSourceInterface $source): void
    {
        $this->externalSupertypes = $source;
        $this->externalFacts = [];
        $this->externalRelationsLoaded = [];
        $this->implicitStringable = [];
        $this->implicitStringableDoubts = [];
        $this->contextCache = [];
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
     * @param (Closure(string): bool)|null $installDeclares Whether the analysed install
     *                                                      declares a type, for
     *                                                      {@see knownTypes()} only;
     *                                                      membership never reads it.
     */
    public function bindGraph(?DependencyGraphInterface $graph, ?iterable $analysedClasses = null, ?Closure $installDeclares = null): void
    {
        $this->installDeclares = $installDeclares;
        $this->graph = $graph;
        $analysedList = $analysedClasses === null
            ? null
            : (\is_array($analysedClasses) ? array_values($analysedClasses) : iterator_to_array($analysedClasses, false));
        $this->nameSpellings = $graph === null ? null : new NameSpellingIndex($graph, $analysedList ?? []);
        $this->extendsMap = null;
        $this->interfaceSources = null;
        $this->implementsMap = null;
        $this->attributesMap = null;
        $this->memberAttributesMap = null;
        $this->traitUseMap = null;
        $this->declarations = null;
        $this->externalFacts = [];
        $this->externalRelationsLoaded = [];
        $this->implicitStringable = [];
        $this->implicitStringableDoubts = [];
        $this->contextCache = [];
        $this->analysed = $analysedList === null
            ? AnalysedDeclarations::unknown()
            : AnalysedDeclarations::of($analysedList);
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

        if ($this->graph === null) {
            return $this->contextCache[$cacheKey] = new ClassContext($fqn, self::deriveShortName($fqn), graphBacked: false);
        }

        if ($class->type === null || $class->type === '') {
            return $this->contextCache[$cacheKey] = new ClassContext($fqn, self::deriveShortName($fqn));
        }

        // PHP class names are case-insensitive and criteria are compared by
        // exact string, so a class PHP declares is named — here and at both
        // ends of every declaration edge — in the one spelling criteria are
        // stored in (LayerCriterionNormalizer::normalizeFqnList()).
        $fqn = $this->spellingOf($fqn);
        $shortName = self::deriveShortName($fqn);
        $this->ensureMapsBuilt();
        $this->applyImplicitStringable($fqn);

        // The subject itself is the first step of the walk. When the run never
        // analysed it and PHP does not declare it, its attribute lists are
        // silence. External source facts may still complete the ancestry walk,
        // so declaration-header completeness is carried separately from cuts.
        $parentCuts = [];
        $interfaceCuts = [];
        $parentOf = PhpBuiltinClassHierarchy::extendsOf(...);

        $attributes = $this->attributesMap[$fqn] ?? PhpBuiltinClassHierarchy::attributesOf($fqn) ?? [];
        $memberAttributes = $this->memberAttributesMap[$fqn] ?? [];
        $parents = $this->bfsClosure($this->supertypesOf($fqn, $parentCuts, $parentOf), $parentCuts, $parentOf);
        $interfaces = $this->collectTransitiveInterfaces($fqn, $parents, $interfaceCuts);
        $implicitStringableKnown = true;
        foreach ([$fqn, ...$parents, ...$interfaces] as $visited) {
            $implicitStringableKnown = $implicitStringableKnown && !isset($this->implicitStringableDoubts[$visited]);
        }

        return $this->contextCache[$cacheKey] = new ClassContext(
            $fqn,
            $shortName,
            $attributes,
            $interfaces,
            $parents,
            ancestryCuts: ['parentChain' => array_keys($parentCuts), 'interfaces' => array_keys($interfaceCuts)],
            memberAttributeFqns: $memberAttributes,
            implicitStringableKnown: $implicitStringableKnown,
            declarationAnalysed: $this->hasOwnDeclarationFacts($fqn),
        );
    }

    /**
     * The types this run met, read from the graph, the declarations and the
     * install bound here — see {@see KnownTypes}.
     */
    public function knownTypes(): KnownTypes
    {
        $installDeclares = $this->externalSupertypes?->isConfigured() === true
            ? $this->externalTypeExists(...)
            : $this->installDeclares;

        return new KnownTypes($this->graph, $this->analysed, $installDeclares);
    }

    private function ensureMapsBuilt(): void
    {
        if ($this->extendsMap !== null) {
            return;
        }

        \assert($this->graph !== null);

        $byType = [
            DependencyType::Extends->name => [],
            DependencyType::Implements->name => [],
            DependencyType::Attribute->name => [],
            DependencyType::TraitUse->name => [],
        ];
        $memberAttributes = [];
        $interfaceSources = [];

        $declarations = [];
        foreach ($this->graph->getClassLikeDeclarations() as $declaration) {
            $fqn = $this->fqnFor($declaration->logical->symbolPath);
            if ($fqn === null) {
                continue;
            }
            $fqn = $this->spellingOf($fqn);
            $declarations[$fqn] = $declaration;
            if ($declaration->type === ClassType::Interface_) {
                $interfaceSources[$fqn] = true;
            }
        }

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

            $sourceFqn = $this->spellingOf($sourceFqn);
            $targetFqn = $this->spellingOf($targetFqn);
            if ($dependency->type === DependencyType::Attribute) {
                if ($dependency->attributeSite === \Qualimetrix\Analysis\Evidence\DependencyModel\Contract\AttributeSite::ClassHeader) {
                    $byType[DependencyType::Attribute->name][$sourceFqn][] = $targetFqn;
                } elseif ($dependency->attributeSite?->isDeclaredMember() === true) {
                    $memberAttributes[$sourceFqn][] = $targetFqn;
                }

                continue;
            }
            if (isset($byType[$dependency->type->name])) {
                $byType[$dependency->type->name][$sourceFqn][] = $targetFqn;
            }
            if ($dependency->interfaceExtends) {
                $interfaceSources[$sourceFqn] = true;
            }
        }

        $this->declarations = $declarations;
        $this->extendsMap = self::dedupeListValues($byType[DependencyType::Extends->name]);
        $this->interfaceSources = $interfaceSources;
        $this->implementsMap = self::dedupeListValues($byType[DependencyType::Implements->name]);
        $this->attributesMap = self::dedupeListValues($byType[DependencyType::Attribute->name]);
        $this->memberAttributesMap = self::dedupeListValues($memberAttributes);
        $this->traitUseMap = self::dedupeListValues($byType[DependencyType::TraitUse->name]);

        foreach (array_keys($declarations) as $fqn) {
            $this->applyImplicitStringable($fqn);
        }
    }

    /**
     * Direct implements + interfaces inherited from parent classes +
     * transitive interface-extends-interface walks. Returned in BFS order
     * from the class outwards; the list is deduplicated.
     *
     * @param list<string> $parentClasses Already-collected transitive
     *                                    parent-class FQNs — for an interface,
     *                                    the interfaces it extends.
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
        \assert($this->interfaceSources !== null);

        $seedQueue = $this->implementsMap[$fqn] ?? PhpBuiltinClassHierarchy::interfacesOf($fqn) ?? [];
        // What an interface extends it has; a class's parents it does not, so
        // `implements: [SomeClass]` stays off the subclasses. Seed only the
        // direct interface parents: treating the whole already-expanded parent
        // closure as direct would reset each one's branch depth.
        $sourceIsInterface = isset($this->interfaceSources[$fqn]);
        if ($sourceIsInterface) {
            foreach ($this->extendsMap[$fqn] ?? [] as $parent) {
                $seedQueue[] = $parent;
            }
        }
        if (!$sourceIsInterface) {
            foreach ($parentClasses as $parent) {
                $declared = $this->implementsMap[$parent] ?? PhpBuiltinClassHierarchy::interfacesOf($parent) ?? [];
                foreach ($declared as $iface) {
                    $seedQueue[] = $iface;
                }
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
     * Each branch includes at most {@see MAX_INHERITANCE_DEPTH} links. When a
     * declaration at that depth names another relation, the next FQN becomes
     * an unresolved cut rather than a silently complete non-match. The limit
     * is per branch: separate branches may read more declarations in total.
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
        $discovered = [];
        // Index cursor instead of array_shift — array_shift re-indexes the
        // entire backing array on every pop (O(n) per call). For deep parent /
        // interface chains this turned into hot O(n²) behaviour.
        $queue = [];
        foreach ($seedQueue as $seed) {
            if (!isset($discovered[$seed])) {
                $discovered[$seed] = true;
                $queue[] = [$seed, 1];
            }
        }
        $cursor = 0;
        $tail = \count($queue);

        while ($cursor < $tail) {
            [$next, $depth] = $queue[$cursor++];
            $result[] = $next;

            $neighbours = $this->supertypesOf($next, $unresolved, $phpAbove, $alsoAbove);
            if ($depth >= self::MAX_INHERITANCE_DEPTH) {
                foreach ($neighbours as $neighbour) {
                    if (!isset($discovered[$neighbour])) {
                        $unresolved[$neighbour] = true;
                    }
                }

                continue;
            }

            foreach ($neighbours as $neighbour) {
                if (isset($discovered[$neighbour])) {
                    continue;
                }
                $discovered[$neighbour] = true;
                $queue[] = [$neighbour, $depth + 1];
                $tail++;
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

        if (
            !$this->analysed->contains($fqn)
            && $phpAbove($fqn) === null
            && !isset($this->externalRelationsLoaded[$fqn])
        ) {
            $this->loadExternalDeclaration($fqn);
        }

        $also = $alsoAbove[$fqn] ?? [];
        $above = $this->extendsMap[$fqn] ?? $phpAbove($fqn);
        if ($above !== null) {
            return [...$above, ...$also];
        }

        // An edge out of the node was recorded, so the run read it.
        if ($also !== []) {
            return $also;
        }

        if (!$this->hasReadableDeclaration($fqn)) {
            $unresolved[$fqn] = true;
        }

        return [];
    }

    private function loadExternalDeclaration(string $fqn): void
    {
        $source = $this->externalSupertypes;
        if ($source === null || !$source->isConfigured() || isset($this->externalRelationsLoaded[$fqn])) {
            return;
        }

        $facts = $this->externalFactsOf($fqn);
        $this->externalRelationsLoaded[$fqn] = true;
        if ($facts->declaredSpelling === null || $facts->classType === null) {
            return;
        }

        \assert($this->extendsMap !== null);
        \assert($this->implementsMap !== null);
        \assert($this->traitUseMap !== null);
        \assert($this->interfaceSources !== null);

        if ($facts->parent !== null) {
            $this->extendsMap[$fqn][] = $facts->parent;
        }
        if ($facts->classType === ClassType::Interface_) {
            $this->interfaceSources[$fqn] = true;
            foreach ($facts->interfaces as $interface) {
                $this->extendsMap[$fqn][] = $interface;
            }
        } else {
            foreach ($facts->interfaces as $interface) {
                $this->implementsMap[$fqn][] = $interface;
            }
        }
        foreach ($facts->traits as $trait) {
            $this->traitUseMap[$fqn][] = $trait;
        }

        $this->extendsMap = self::dedupeListValues($this->extendsMap);
        $this->implementsMap = self::dedupeListValues($this->implementsMap);
        $this->traitUseMap = self::dedupeListValues($this->traitUseMap);
    }

    private function applyImplicitStringable(string $fqn): void
    {
        $stringable = $this->implicitStringableStatus($fqn, []);
        if ($stringable === null) {
            $this->implicitStringableDoubts[$fqn] = true;

            return;
        }
        if (!$stringable) {
            return;
        }

        $type = $this->classTypeOf($fqn);
        if ($type === ClassType::Interface_) {
            \assert($this->extendsMap !== null);
            $this->extendsMap[$fqn][] = 'Stringable';
            $this->extendsMap = self::dedupeListValues($this->extendsMap);

            return;
        }
        if ($type !== ClassType::Class_) {
            return;
        }

        \assert($this->implementsMap !== null);
        $this->implementsMap[$fqn][] = 'Stringable';
        $this->implementsMap = self::dedupeListValues($this->implementsMap);
    }

    /**
     * @param array<string, true> $visiting
     */
    private function implicitStringableStatus(string $fqn, array $visiting): ?bool
    {
        if ($fqn === 'Stringable') {
            return true;
        }
        $builtinInterfaces = PhpBuiltinClassHierarchy::interfacesOf($fqn);
        if ($builtinInterfaces !== null) {
            return \in_array('Stringable', $builtinInterfaces, true);
        }
        if (\array_key_exists($fqn, $this->implicitStringable)) {
            return $this->implicitStringable[$fqn];
        }
        if (isset($visiting[$fqn]) || \count($visiting) >= self::MAX_INHERITANCE_DEPTH) {
            return null;
        }

        $facts = $this->declarationFacts($fqn);
        if ($facts === null) {
            return $this->implicitStringable[$fqn] = null;
        }
        if ($facts->declaresToString) {
            return $this->implicitStringable[$fqn] = true;
        }

        $visiting[$fqn] = true;
        $unknown = $facts->aliasesTraitMethodAsToString;
        \assert($this->extendsMap !== null);
        \assert($this->implementsMap !== null);
        \assert($this->traitUseMap !== null);
        foreach ([
            ...($this->traitUseMap[$fqn] ?? []),
            ...($this->extendsMap[$fqn] ?? []),
            ...($this->implementsMap[$fqn] ?? []),
        ] as $related) {
            $status = $this->implicitStringableStatus($related, $visiting);
            if ($status === true) {
                return $this->implicitStringable[$fqn] = true;
            }
            $unknown = $unknown || $status === null;
        }

        return $this->implicitStringable[$fqn] = $unknown ? null : false;
    }

    private function declarationFacts(string $fqn): ClassLikeDeclaration|ExternalSupertypes|null
    {
        \assert($this->declarations !== null);
        if (isset($this->declarations[$fqn])) {
            return $this->declarations[$fqn];
        }
        if (!isset($this->externalRelationsLoaded[$fqn])) {
            $this->loadExternalDeclaration($fqn);
        }

        $facts = $this->externalFacts[$fqn] ?? null;

        return $facts?->declaredSpelling === null ? null : $facts;
    }

    private function classTypeOf(string $fqn): ?ClassType
    {
        $facts = $this->declarationFacts($fqn);

        return $facts instanceof ClassLikeDeclaration ? $facts->type : $facts?->classType;
    }

    private function hasReadableDeclaration(string $fqn): bool
    {
        if ($this->analysed->contains($fqn)) {
            return true;
        }

        return ($this->externalFacts[$fqn]->declaredSpelling ?? null) !== null;
    }

    private function hasOwnDeclarationFacts(string $fqn): bool
    {
        \assert($this->declarations !== null);
        if (isset($this->declarations[$fqn]) || PhpBuiltinClassRegistry::canonicalName($fqn) !== null) {
            return true;
        }
        if (($this->externalFacts[$fqn]->declaredSpelling ?? null) !== null) {
            return false;
        }

        return $this->analysed->contains($fqn);
    }

    private function externalTypeExists(string $fqn): bool
    {
        $facts = $this->externalFactsOf($fqn);

        return $facts->placed && $facts->declaredSpelling === ltrim($fqn, '\\');
    }

    private function externalFactsOf(string $fqn): ExternalSupertypes
    {
        if (isset($this->externalFacts[$fqn])) {
            return $this->externalFacts[$fqn];
        }

        $source = $this->externalSupertypes;

        return $this->externalFacts[$fqn] = $source === null || !$source->isConfigured()
            ? ExternalSupertypes::notPlaced()
            : $source->supertypesOf($fqn);
    }

    private function spellingOf(string $fqn): string
    {
        return PhpBuiltinClassRegistry::canonicalName($fqn)
            ?? $this->nameSpellings?->spellingOf($fqn)
            ?? $fqn;
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
