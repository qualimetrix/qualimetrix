<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation\Observation;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerMatch;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerShadowing;
use Qualimetrix\Core\Symbol\SymbolLevel;
use WeakMap;

/**
 * Walks one run's classes and dependency edges once and answers both layer
 * verdicts from the same observation.
 *
 * **Why the walk is shared rather than repeated.** The class walk visits every
 * analysed declaration and the edge walk every dependency in the graph; the
 * edge verdict needs the first only for coverage, the declaration verdict needs
 * the second only for layers that exist outside the analysed set. Giving each
 * verdict its own collector would double the most expensive part of the rule
 * phase, and letting one verdict hand its locals to the other would make the
 * two depend on execution order.
 *
 * **Memoisation is per {@see AnalysisContext} instance, deliberately.** A
 * {@see WeakMap} keyed by the context means a second run — a new context —
 * recomputes, and nothing survives between runs. That keeps the CLAUDE.md
 * "stateless rules" contract intact where it matters: no count from one
 * `analyze()` can reach the next, because the key that would carry it is gone.
 *
 * The short-circuits live here rather than in the two callers so that
 * "disabled" and "no layers declared" have one answer instead of two that can
 * drift: both produce `null`, and both verdicts report nothing. A third state
 * — the policy not prepared at all — is deliberately not one of them: it
 * throws, because memoising its emptiness would silence both verdicts for the
 * whole run.
 *
 * @qmx-threshold coupling.instability warning=0.82 -- Ca=3, Ce=13 (I=0.8125): three verdicts read this collector, and it names what the shared walk reads (run context, prepared policy, layer-matching primitives, the options contract both consumers' gates answer through) and the typed values it emits. Four of those edges are the value objects that replaced positional tuples and array shapes: `ClassWalkEvidence` and `EdgeWalkEvidence` are new, while `ForbiddenEdge` and `ShadowedClass` now carry, as counted edges of their own, the `Dependency` and `MatchedCriterion` the shapes named only in PHPDoc. The threshold is inclusive: 0.82 keeps today's 0.8125 silent and reports the next efferent edge, which takes Ce to 14 and instability to 0.824.
 */
final class LayerEvidenceCollector
{
    /** @var WeakMap<AnalysisContext, list<LayerEvidence|null>> */
    private WeakMap $memo;

    /**
     * Both consumers' options, because what the walk materialises depends on
     * which of them the configuration turned on. They are two objects since
     * `architecture.unassigned-class` became a producer of its own, and this
     * collector is where the disjunction between them belongs — it is the one
     * place that knows both.
     *
     * Typed as the generic options contract because each one's gate is all
     * the walk reads of it: naming the two classes would point this directory
     * back at the verdicts that read it.
     */
    public function __construct(
        private readonly RuleOptionsInterface $layerViolation,
        private readonly RuleOptionsInterface $unassignedClass,
        private readonly ArchitecturePolicy $processor,
    ) {
        $this->memo = new WeakMap();
    }

    /**
     * `null` when **every** consumer is disabled or the run declares no
     * layers — the two states a caller may legitimately reach. A caller whose
     * own gate is off still has to check it: this method answers "is there
     * evidence", not "may you report". Reaching an unprepared policy is
     * refused instead: see {@see walk()}.
     */
    public function collect(AnalysisContext $context): ?LayerEvidence
    {
        $memoized = $this->memo[$context] ?? null;

        if ($memoized !== null) {
            return $memoized[0];
        }

        $evidence = $this->walk($context);
        $this->memo[$context] = [$evidence];

        return $evidence;
    }

    private function walk(AnalysisContext $context): ?LayerEvidence
    {
        // Any consumer, not the first one. This gate used to read the
        // layer-violation rule's `enabled` alone, which made
        // `architecture.unassigned-class` — a producer of its own since ADR
        // 0030 — fall silent when its neighbour was switched off in options,
        // the exact coupling the split exists to remove. Publication stays
        // each consumer's own decision: the rule, the validator and the
        // unassigned-class rule each check their own gate before emitting.
        if (!$this->layerViolation->isEnabled() && !$this->unassignedClass->isEnabled()) {
            return null;
        }

        $architecture = $this->processor->getPreparedConfiguration();

        // Refused rather than memoised. An unprepared policy is not "no
        // layers": it is a caller that reached the verdicts before the run
        // primed them, and the memo would pin that emptiness to this context
        // for the rest of the run — both verdicts silently reporting nothing
        // even after preparation. The run reaches this only through
        // RuleProducerPreparation, which either prepares the policy or leaves
        // the producer out of the selection entirely; anything else is a wiring
        // mistake and should say so. Mirrors ArchitecturePolicy::classify().
        if ($architecture === null) {
            throw new LogicException(
                'LayerEvidenceCollector::collect() reached an unprepared ArchitecturePolicy. The layer verdicts'
                . ' read a configuration prepared for the run; a producer whose policy was never prepared must be'
                . ' left out of the selection, not asked for evidence.',
            );
        }

        if ($architecture->isEmpty()) {
            return null;
        }

        // Graph binding already happened inside ArchitecturePolicy::prepare()
        // per ADR 0008 §2. The registry's ClassContextFactory therefore sees
        // the current run's graph; no rebind needed here.
        $classWalk = $this->collectClassEvidence($architecture, $context);
        $edgeWalk = $this->collectEdgeEvidence($architecture, $context);

        $coverageState = $edgeWalk->coverageState;
        $coverageState['classes'] += $classWalk->uncoveredClasses;
        // The class walk books every analysed class that is undecided or in
        // doubt, whatever the coverage mode, so an edge end it did not book
        // was not analysed. Taken before the merges below, which are what
        // erase the difference.
        $coverageState['undecidableOutsidePaths'] = array_diff_key($coverageState['undecidable'], $classWalk->undecidableClasses);
        $coverageState['undecidable'] += $classWalk->undecidableClasses;
        $coverageState['doubtedOutsidePaths'] = array_diff_key($coverageState['doubted'], $classWalk->doubtedClasses);
        $coverageState['doubted'] += $classWalk->doubtedClasses;

        // A layer matched only as one end of a dependency edge (e.g. a vendor
        // namespace outside `paths:`, never a class in the analysed set) is
        // still "reached" — merge edge-side hits into the class-side hit maps
        // so `architecture.unreachable-layer` doesn't contradict
        // `architecture.layer-violation` about the very same layer.
        $symbolSets = $classWalk->symbolSets;
        foreach ($symbolSets as $column => $sets) {
            $symbolSets[$column] = self::mergeMatchedSymbols($sets, $edgeWalk->symbolSets[$column]);
        }

        return new LayerEvidence(
            architecture: $architecture,
            forbiddenEdges: $edgeWalk->forbiddenEdges,
            assignedHits: self::mergeHits($classWalk->assignedHits, $edgeWalk->assignedHits),
            symbolSets: $symbolSets,
            shadowEvidence: $classWalk->shadowEvidence,
            unassigned: ['classes' => $classWalk->uncoveredClasses, 'analysed' => $classWalk->analysedDeclarations],
            coverageState: $coverageState,
        );
    }

    /**
     * Whether the per-class walk must materialise the set of declarations
     * outside every layer.
     *
     * Two independent consumers, so the predicate is their disjunction rather
     * than either alone: the project that turned `coverage` off because
     * dependency-edge ends drowned it in vendor code is precisely the one that
     * turns `architecture.unassigned-class` on, and reading the coverage mode
     * alone would leave that channel with no evidence to report.
     */
    private function materializesUncovered(ArchitectureConfiguration $architecture): bool
    {
        return $architecture->coverage() !== CoverageMode::Ignore || $this->unassignedClass->isEnabled();
    }

    /**
     * @param array<string, int> $into
     * @param array<string, int> $from
     *
     * @return array<string, int>
     */
    private static function mergeHits(array $into, array $from): array
    {
        foreach ($from as $layerName => $count) {
            $into[$layerName] = ($into[$layerName] ?? 0) + $count;
        }

        return $into;
    }

    /**
     * Walks `metrics->all(SymbolLevel::Class_)` once into a
     * {@see ClassWalkEvidence}; what its fields feed and why they have the
     * shape they do:
     *
     * 1. `assignedHits` — per-layer count of classes that ended up in that
     *    layer (feeds `architecture.unreachable-layer`), and the `matched`
     *    column of `symbolSets` — per-layer set of the distinct classes whose criteria the layer
     *    matched at all, winning or not (feeds
     *    `architecture.pending-layer-matched`, which is silent exactly where
     *    a layer matched nothing — see
     *    {@see \Qualimetrix\Analysis\Policy\Architecture\LayerViolation\DeclaredLayerReachability::pendingLayersMatched()}).
     * 2. The `excluded` column of `symbolSets` — per-layer set of the distinct classes the
     *    layer's `exclude:` clause removed (feeds
     *    `architecture.unmatched-exclude`).
     * 3. `shadowEvidence` — per (assigned, shadowed) pair, list of evidence
     *    entries carrying the class FQN plus the specific criterion descriptors
     *    that matched on each side (feeds `architecture.potential-shadow`
     *    without re-walking the layer list at emission time). Descriptors
     *    carry the criterion kind (pattern / suffix / attribute / implements
     *    / extends) so the message can name the actual cause of the shadow.
     * 4. `uncoveredClasses` — canonical logical class key to display FQN for
     *    every analysed class outside all declared layers. Canonical keys make
     *    the later merge with dependency-edge coverage deterministic and
     *    deduplicate a class observed through both repository and graph views.
     *    Materialised only when {@see materializesUncovered()} says a
     *    consumer exists, because the map is the size of the unclassified
     *    codebase.
     * 5. `analysedDeclarations` — how many class-like declarations the walk
     *    saw, the denominator `architecture.unassigned-class` reports its
     *    percentage against.
     * 6. `undecidableClasses` — the subset of `uncoveredClasses` that is
     *    outside every layer only because the run could not answer some
     *    layer's criteria about it: a chain that left the analysed set, or a
     *    symbol whose own declaration the run never read. Kept apart because
     *    the two gaps ask different things of the reader — one is closed by
     *    declaring a layer, the other is not closed by anything the author
     *    writes in `layers:`.
     * 7. `doubtedClasses` — analysed classes that ARE assigned while a layer
     *    bearing on the assignment could not be answered
     *    ({@see \Qualimetrix\Analysis\Policy\Architecture\Layer\LayerRegistry::undecidedLayers()}
     *    decides which do): the assignment stands and the doubt is published
     *    beside it.
     *
     * 6 and 7 are booked whatever the coverage mode, unlike 4: they are the
     * size of the doubt rather than of the unclassified codebase, and
     * `architecture.doubted-assignment` reads them in every mode.
     *
     * The per-layer symbol sets travel as one array of six columns —
     * `matched`, `excluded`, `unanswered` (the symbols whose `exclude:` the
     * layer could not answer), `undecided` (the symbols the layer could not
     * answer about while it bore on their assignment), `contended` (the
     * symbols the layer could still own once that is answered) and
     * `ownsIfExcluded` (the symbols the layer would own if an unanswered
     * `exclude:` in front of it removed them) — because they are filled and
     * merged the same way.
     *
     * `metrics->all(SymbolLevel::Class_)` enumerates what the collectors
     * recorded, and class scope opens on every `ClassLike` — interfaces,
     * traits and enums included. The blind spot is therefore a declaration no
     * collector recorded any class-level metric for: it is absent here and
     * counts as assigned.
     */
    private function collectClassEvidence(
        ArchitectureConfiguration $architecture,
        AnalysisContext $context,
    ): ClassWalkEvidence {
        $registry = $architecture->registry();
        $materializeUncovered = $this->materializesUncovered($architecture);

        $assignedHits = [];
        $matchedSymbols = [];
        $excludedSymbols = [];
        $unansweredSymbols = [];
        $undecidedSymbols = [];
        $contendedSymbols = [];
        $ownsIfExcludedSymbols = [];
        foreach ($registry->layerNames() as $layerName) {
            $assignedHits[$layerName] = 0;
            $matchedSymbols[$layerName] = [];
            $excludedSymbols[$layerName] = [];
            $unansweredSymbols[$layerName] = [];
        }

        /** @var array<string, array<string, list<ShadowedClass>>> $shadowEvidence */
        $shadowEvidence = [];
        $uncoveredClasses = [];
        $undecidableClasses = [];
        $doubtedClasses = [];
        $analysedDeclarations = 0;

        foreach ($context->metrics->all(SymbolLevel::Class_) as $classSymbol) {
            $analysedDeclarations++;
            $matches = $registry->resolveAll($classSymbol->symbolPath);

            // Booked before the no-match early return, not after: the class an
            // `exclude:` clause removed usually matches NO layer at all, which
            // is the branch below. Tallying after it would leave every such
            // clause looking like it removed nothing — the exact false finding
            // this evidence exists to avoid.
            $excludedSymbols = self::tallyExcludedEnd(
                $excludedSymbols,
                $registry->excludedLayers($classSymbol->symbolPath),
                $classSymbol->symbolPath->toCanonical(),
            );
            $unansweredSymbols = self::tallyExcludedEnd(
                $unansweredSymbols,
                $registry->unansweredExcludeLayers($classSymbol->symbolPath),
                $classSymbol->symbolPath->toCanonical(),
            );
            $undecidedLayers = $registry->undecidedLayers($classSymbol->symbolPath);
            $undecidedSymbols = self::tallyExcludedEnd($undecidedSymbols, $undecidedLayers, $classSymbol->symbolPath->toCanonical());
            $contendedSymbols = self::tallyExcludedEnd(
                $contendedSymbols,
                $registry->contenders($classSymbol->symbolPath),
                $classSymbol->symbolPath->toCanonical(),
            );

            if ($matches === []) {
                if ($materializeUncovered) {
                    $uncoveredClasses[$classSymbol->symbolPath->toCanonical()] = $classSymbol->symbolPath->toString();
                }
                $undecidableClasses = self::tallyUnansweredEnd($undecidableClasses, $undecidedLayers, $classSymbol->symbolPath->toCanonical(), $classSymbol->symbolPath->toString());

                continue;
            }

            $assigned = $matches[0];
            $doubtedClasses = self::tallyUnansweredEnd($doubtedClasses, $undecidedLayers, $classSymbol->symbolPath->toCanonical(), $classSymbol->symbolPath->toString());
            $assignedHits[$assigned->layerName] = ($assignedHits[$assigned->layerName] ?? 0) + 1;
            $matchedSymbols = self::tallyMatchedEnd($matchedSymbols, $matches, $classSymbol->symbolPath->toCanonical());

            $established = $registry->establishedMatches($classSymbol->symbolPath);
            $ownsIfExcludedSymbols = self::tallyOwnerIfExcluded(
                $ownsIfExcludedSymbols,
                $assigned,
                $established,
                $classSymbol->symbolPath->toCanonical(),
            );
            $shadowing = $established[0] ?? null;
            if ($shadowing === null) {
                continue;
            }
            $classFqn = $classSymbol->symbolPath->toString();
            foreach (LayerShadowing::reportableShadows($established) as $shadowed) {
                $shadowEvidence[$shadowing->layerName][$shadowed->layerName][] = new ShadowedClass(
                    $classFqn,
                    $shadowing->primaryCriterion(),
                    $shadowed->primaryCriterion(),
                );
            }
        }

        return new ClassWalkEvidence(
            assignedHits: $assignedHits,
            symbolSets: [
                'matched' => $matchedSymbols,
                'excluded' => $excludedSymbols,
                'unanswered' => $unansweredSymbols,
                'undecided' => $undecidedSymbols,
                'contended' => $contendedSymbols,
                'ownsIfExcluded' => $ownsIfExcludedSymbols,
            ],
            shadowEvidence: $shadowEvidence,
            uncoveredClasses: $uncoveredClasses,
            analysedDeclarations: $analysedDeclarations,
            undecidableClasses: $undecidableClasses,
            doubtedClasses: $doubtedClasses,
        );
    }

    /**
     * Records the symbol under the first match the run established when that
     * is not the assigned layer — the layer that would own the symbol if the
     * unanswered `exclude:` of every match in front of it removed it. The
     * assigned layer is that match whenever no such clause stands in front
     * of it, and then there is nothing to record.
     *
     * @param array<string, array<string, true>> $map layer name => set of canonical symbols
     * @param list<LayerMatch> $established As returned by
     *                                      {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\LayerRegistry::establishedMatches()}.
     *
     * @return array<string, array<string, true>>
     */
    private static function tallyOwnerIfExcluded(array $map, LayerMatch $assigned, array $established, string $symbolKey): array
    {
        $owner = $established[0] ?? null;
        if ($owner !== null && $owner->layerName !== $assigned->layerName) {
            $map[$owner->layerName][$symbolKey] = true;
        }

        return $map;
    }

    /**
     * Records a symbol some layer bearing on its assignment could not answer
     * about: into the undecidable map for a symbol no layer claims, into the
     * doubted map for one that stands assigned. Which unanswered layers bear
     * on the assignment is {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\LayerRegistry::undecidedLayers()}'s
     * to say, so the two maps differ only in which symbols the caller hands
     * in.
     *
     * Empty {@code $undecidedLayers} leaves the map untouched: a class every
     * layer decided against is an ordinary coverage gap, and a class every
     * layer bearing on it answered is an assignment with nothing in doubt.
     *
     * @param array<string, string> $map canonical key => display FQN
     * @param list<string> $undecidedLayers
     *
     * @return array<string, string>
     */
    private static function tallyUnansweredEnd(array $map, array $undecidedLayers, string $canonical, string $display): array
    {
        if ($undecidedLayers !== []) {
            $map[$canonical] = $display;
        }

        return $map;
    }

    /**
     * Walks the dependency graph into an {@see EdgeWalkEvidence}: the edges the
     * allow-list rejects, the coverage-state struct used by
     * `architecture.coverage-gap` (counts of unmatched ends + the set of
     * unclassified class FQNs, and the undecidable and doubted ends), a
     * per-layer assignment count, and the same six per-layer symbol-set
     * columns the class walk fills, at either end of an edge.
     *
     * The hit map exists because {@see collectClassEvidence()} only walks
     * `metrics->all(SymbolLevel::Class_)` — classes in the analysed path set.
     * A layer that matches exclusively outside that set (e.g. a vendor
     * namespace such as `ClickHouseDB\**`, reachable only as a dependency
     * TARGET) would otherwise always show zero hits and be reported
     * `architecture.unreachable-layer` even while `architecture.layer-violation`
     * reports a real edge into it — a self-contradictory diagnostic pair.
     * Merging edge-side hits into the class-side count in {@see walk()}
     * fixes that without weakening unreachable-layer's typo-detection case:
     * a layer matching neither a class nor an edge end still gets zero hits.
     * The same holds for the `contended` column, which the edge walk also
     * fills: {@see LayerEvidence::reachedCounts()} decides which of it counts,
     * because a criterion goes unanswered about a symbol the run never reads
     * in every run, typo or not.
     */
    private function collectEdgeEvidence(ArchitectureConfiguration $architecture, AnalysisContext $context): EdgeWalkEvidence
    {
        $forbidden = [];
        $sourceEdges = 0;
        $targetEdges = 0;
        $classes = [];
        $undecidable = [];
        $doubted = [];
        $assignedHits = [];
        $matchedSymbols = [];
        $excludedSymbols = [];
        $unansweredSymbols = [];
        $undecidedSymbols = [];
        $contendedSymbols = [];
        $ownsIfExcludedSymbols = [];

        $graph = $context->dependencyGraph;
        if ($graph === null) {
            return new EdgeWalkEvidence(
                forbiddenEdges: $forbidden,
                coverageState: ['sourceEdges' => 0, 'targetEdges' => 0, 'classes' => [], 'undecidable' => [], 'doubted' => []],
                assignedHits: $assignedHits,
                symbolSets: ['matched' => [], 'excluded' => [], 'unanswered' => [], 'undecided' => [], 'contended' => [], 'ownsIfExcluded' => []],
            );
        }

        $registry = $architecture->registry();
        foreach ($graph->getAllDependencies() as $dependency) {
            $fromMatches = $registry->resolveAll($dependency->sourceLogical());
            $toMatches = $registry->resolveAll($dependency->targetLogical());

            $matchedSymbols = self::tallyMatchedEnd($matchedSymbols, $fromMatches, $dependency->sourceLogical()->toCanonical());
            $matchedSymbols = self::tallyMatchedEnd($matchedSymbols, $toMatches, $dependency->targetLogical()->toCanonical());

            // Both ends, and before the unmatched-end early return below for
            // the same reason the class walk books before its own.
            $excludedSymbols = self::tallyExcludedEnd($excludedSymbols, $registry->excludedLayers($dependency->sourceLogical()), $dependency->sourceLogical()->toCanonical());
            $excludedSymbols = self::tallyExcludedEnd($excludedSymbols, $registry->excludedLayers($dependency->targetLogical()), $dependency->targetLogical()->toCanonical());
            $unansweredSymbols = self::tallyExcludedEnd($unansweredSymbols, $registry->unansweredExcludeLayers($dependency->sourceLogical()), $dependency->sourceLogical()->toCanonical());
            $unansweredSymbols = self::tallyExcludedEnd($unansweredSymbols, $registry->unansweredExcludeLayers($dependency->targetLogical()), $dependency->targetLogical()->toCanonical());

            $fromMatch = self::tallyEnd($fromMatches, $dependency->sourceLogical()->toCanonical(), $dependency->sourceLogical()->toString(), $assignedHits, $classes, $sourceEdges);
            $toMatch = self::tallyEnd($toMatches, $dependency->targetLogical()->toCanonical(), $dependency->targetLogical()->toString(), $assignedHits, $classes, $targetEdges);

            // Each end on its own, before the unassigned-end return below: the
            // doubt about one end is a fact about that end, and an edge whose
            // other end no layer claims is still an edge that reached it.
            foreach ([[$fromMatch, $dependency->sourceLogical()], [$toMatch, $dependency->targetLogical()]] as [$match, $end]) {
                $undecidedLayers = $registry->undecidedLayers($end);
                $undecidedSymbols = self::tallyExcludedEnd($undecidedSymbols, $undecidedLayers, $end->toCanonical());
                $contendedSymbols = self::tallyExcludedEnd($contendedSymbols, $registry->contenders($end), $end->toCanonical());
                if ($match === null) {
                    $undecidable = self::tallyUnansweredEnd($undecidable, $undecidedLayers, $end->toCanonical(), $end->toString());
                } else {
                    $doubted = self::tallyUnansweredEnd($doubted, $undecidedLayers, $end->toCanonical(), $end->toString());
                    $ownsIfExcludedSymbols = self::tallyOwnerIfExcluded(
                        $ownsIfExcludedSymbols,
                        $match,
                        $registry->establishedMatches($end),
                        $end->toCanonical(),
                    );
                }
            }

            if ($fromMatch === null || $toMatch === null) {
                continue;
            }

            if ($architecture->policy()->isAllowed($fromMatch->layerName, $toMatch->layerName, $dependency->type)) {
                continue;
            }

            $forbidden[] = new ForbiddenEdge($dependency, $fromMatch, $toMatch);
        }

        return new EdgeWalkEvidence(
            forbiddenEdges: $forbidden,
            coverageState: ['sourceEdges' => $sourceEdges, 'targetEdges' => $targetEdges, 'classes' => $classes, 'undecidable' => $undecidable, 'doubted' => $doubted],
            assignedHits: $assignedHits,
            symbolSets: [
                'matched' => $matchedSymbols,
                'excluded' => $excludedSymbols,
                'unanswered' => $unansweredSymbols,
                'undecided' => $undecidedSymbols,
                'contended' => $contendedSymbols,
                'ownsIfExcluded' => $ownsIfExcludedSymbols,
            ],
        );
    }

    /**
     * Books one end of a dependency edge: the winning layer gets a hit, an
     * unmatched end becomes both an unmatched-edge count and an entry in the
     * unclassified set. Written once because the two ends differ only in
     * which counter they raise.
     *
     * @param list<LayerMatch> $matches
     * @param array<string, int> $assignedHits
     * @param array<string, string> $classes
     */
    private static function tallyEnd(
        array $matches,
        string $canonical,
        string $display,
        array &$assignedHits,
        array &$classes,
        int &$unmatchedEdges,
    ): ?LayerMatch {
        $match = $matches[0] ?? null;

        if ($match === null) {
            $unmatchedEdges++;
            $classes[$canonical] = $display;

            return null;
        }

        $assignedHits[$match->layerName] = ($assignedHits[$match->layerName] ?? 0) + 1;

        return $match;
    }

    /**
     * Records the symbol under every layer that matched it, not just the one
     * that won it — the predicate `architecture.pending-layer-matched` needs
     * and `architecture.unreachable-layer` deliberately does not.
     *
     * A set keyed by the symbol's canonical form rather than a counter,
     * because the same symbol is seen many times: once by the class walk and
     * once per dependency edge it sits at either end of. A counter therefore
     * reported edge multiplicity — two classes joined by four edges read as
     * eight — and `architecture.pending-layer-matched` printed a number that
     * answered no question anyone asks.
     *
     * @param array<string, array<string, true>> $matchedSymbols layer name => set of canonical symbols
     * @param list<LayerMatch> $matches
     * @param string $symbolKey The matched symbol in {@see \Qualimetrix\Core\Symbol\SymbolPath::toCanonical()} form.
     *
     * @return array<string, array<string, true>>
     */
    private static function tallyMatchedEnd(array $matchedSymbols, array $matches, string $symbolKey): array
    {
        foreach ($matches as $match) {
            $matchedSymbols[$match->layerName][$symbolKey] = true;
        }

        return $matchedSymbols;
    }

    /**
     * Records the symbol under every named layer — those whose `exclude:`
     * clause removed it, those whose clause could not answer about it, those
     * that could not answer about it at all, or those that could still own it.
     *
     * A set for the same reason {@see tallyMatchedEnd()} keeps one: the same
     * symbol is seen once by the class walk and once per dependency edge it
     * sits at an end of, and `architecture.unmatched-exclude` asks whether the
     * set is empty, not how often it was refilled.
     *
     * @param array<string, array<string, true>> $excludedSymbols layer name => set of canonical symbols
     * @param list<string> $layerNames
     *
     * @return array<string, array<string, true>>
     */
    private static function tallyExcludedEnd(array $excludedSymbols, array $layerNames, string $symbolKey): array
    {
        foreach ($layerNames as $layerName) {
            $excludedSymbols[$layerName][$symbolKey] = true;
        }

        return $excludedSymbols;
    }

    /**
     * @param array<string, array<string, true>> $into
     * @param array<string, array<string, true>> $from
     *
     * @return array<string, array<string, true>>
     */
    private static function mergeMatchedSymbols(array $into, array $from): array
    {
        foreach ($from as $layerName => $symbols) {
            $into[$layerName] = ($into[$layerName] ?? []) + $symbols;
        }

        return $into;
    }
}
