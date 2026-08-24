<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerMatch;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerShadowing;
use Qualimetrix\Core\Symbol\SymbolType;
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
 * @qmx-threshold coupling.instability warning=0.82 -- Ca=2, raw Ce=8 (I=0.80): AnalysisContext, ArchitecturePolicy, ArchitectureConfiguration, LayerMatch, LayerShadowing, SymbolType, WeakMap and the LayerEvidence it constructs are each read to answer one of the two verdicts this collector shares between them; none can be dropped without duplicating the walk it exists to share. Raw Ce=8 gets one-edge headroom: at Ce=9, I=0.818, still under 0.82; at Ce=10, I=0.833, over it.
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
     */
    public function __construct(
        private readonly LayerViolationOptions $options,
        private readonly UnassignedClassOptions $unassignedClass,
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
        if (!$this->options->isEnabled() && !$this->unassignedClass->isEnabled()) {
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
        [$assignedHits, $matchedSymbols, $shadowEvidence, $uncoveredClasses, $analysedDeclarations] = $this->collectClassEvidence(
            $architecture,
            $context,
        );

        [$forbiddenEdges, $coverageState, $edgeAssignedHits, $edgeMatchedSymbols] = $this->collectEdgeEvidence($architecture, $context);
        $coverageState['classes'] += $uncoveredClasses;

        // A layer matched only as one end of a dependency edge (e.g. a vendor
        // namespace outside `paths:`, never a class in the analysed set) is
        // still "reached" — merge edge-side hits into the class-side hit maps
        // so `architecture.unreachable-layer` doesn't contradict
        // `architecture.layer-violation` about the very same layer.
        $assignedHits = self::mergeHits($assignedHits, $edgeAssignedHits);
        $matchedSymbols = self::mergeMatchedSymbols($matchedSymbols, $edgeMatchedSymbols);

        return new LayerEvidence(
            architecture: $architecture,
            forbiddenEdges: $forbiddenEdges,
            assignedHits: $assignedHits,
            matchedSymbols: $matchedSymbols,
            shadowEvidence: $shadowEvidence,
            unassigned: ['classes' => $uncoveredClasses, 'analysed' => $analysedDeclarations],
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
     * Walks `metrics->all(SymbolType::Class_)` once and collects four local
     * structures:
     *
     * 1. `assignedHits` — per-layer count of classes that ended up in that
     *    layer (feeds `architecture.unreachable-layer`), and `matchedSymbols`
     *    — per-layer set of the distinct classes whose criteria the layer
     *    matched at all, winning or not (feeds
     *    `architecture.pending-layer-matched`, which is silent exactly where
     *    a layer matched nothing — see
     *    {@see DeclaredLayerReachability::pendingLayersMatched()}).
     * 2. `shadowEvidence` — per (assigned, shadowed) pair, list of evidence
     *    entries carrying the class FQN plus the specific criterion descriptors
     *    that matched on each side (feeds `architecture.potential-shadow`
     *    without re-walking the layer list at emission time). Descriptors
     *    carry the criterion kind (pattern / suffix / attribute / implements
     *    / extends) so the message can name the actual cause of the shadow.
     * 3. `uncoveredClasses` — canonical logical class key to display FQN for
     *    every analysed class outside all declared layers. Canonical keys make
     *    the later merge with dependency-edge coverage deterministic and
     *    deduplicate a class observed through both repository and graph views.
     *    Materialised only when {@see materializesUncovered()} says a
     *    consumer exists, because the map is the size of the unclassified
     *    codebase.
     * 4. `analysedDeclarations` — how many class-like declarations the walk
     *    saw, the denominator `architecture.unassigned-class` reports its
     *    percentage against.
     *
     * `metrics->all(SymbolType::Class_)` enumerates what the collectors
     * recorded, and class scope opens on every `ClassLike` — interfaces,
     * traits and enums included. The blind spot is therefore a declaration no
     * collector recorded any class-level metric for: it is absent here and
     * counts as assigned.
     *
     * @return array{0: array<string, int>, 1: array<string, array<string, true>>, 2: array<string, array<string, list<array{fqn: string, assignedCriterion: \Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterion, shadowedCriterion: \Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterion}>>>, 3: array<string, string>, 4: int}
     */
    private function collectClassEvidence(
        ArchitectureConfiguration $architecture,
        AnalysisContext $context,
    ): array {
        $registry = $architecture->registry();
        $materializeUncovered = $this->materializesUncovered($architecture);

        $assignedHits = [];
        $matchedSymbols = [];
        foreach ($registry->layerNames() as $layerName) {
            $assignedHits[$layerName] = 0;
            $matchedSymbols[$layerName] = [];
        }

        /** @var array<string, array<string, list<array{fqn: string, assignedCriterion: \Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterion, shadowedCriterion: \Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterion}>>> $shadowEvidence */
        $shadowEvidence = [];
        $uncoveredClasses = [];
        $analysedDeclarations = 0;

        foreach ($context->metrics->all(SymbolType::Class_) as $classSymbol) {
            $analysedDeclarations++;
            $matches = $registry->resolveAll($classSymbol->symbolPath);
            if ($matches === []) {
                if ($materializeUncovered) {
                    $uncoveredClasses[$classSymbol->symbolPath->toCanonical()] = $classSymbol->symbolPath->toString();
                }

                continue;
            }

            $assigned = $matches[0];
            $assignedHits[$assigned->layerName] = ($assignedHits[$assigned->layerName] ?? 0) + 1;
            $matchedSymbols = self::tallyMatchedEnd($matchedSymbols, $matches, $classSymbol->symbolPath->toCanonical());

            $matchCount = \count($matches);
            if ($matchCount === 1) {
                continue;
            }

            $classFqn = $classSymbol->symbolPath->toString();
            $assignedCriterion = $assigned->primaryCriterion();
            foreach (LayerShadowing::reportableShadows($matches) as $shadowed) {
                $shadowEvidence[$assigned->layerName][$shadowed->layerName][] = [
                    'fqn' => $classFqn,
                    'assignedCriterion' => $assignedCriterion,
                    'shadowedCriterion' => $shadowed->primaryCriterion(),
                ];
            }
        }

        return [$assignedHits, $matchedSymbols, $shadowEvidence, $uncoveredClasses, $analysedDeclarations];
    }

    /**
     * Walks the dependency graph and records the edges the allow-list rejects,
     * the coverage-state struct used by `architecture.coverage` (counts of
     * unmatched ends + the set of unclassified class FQNs), a per-layer
     * assignment count, and a per-layer set of the distinct symbols matched at
     * either end of an edge.
     *
     * The hit map exists because {@see collectClassEvidence()} only walks
     * `metrics->all(SymbolType::Class_)` — classes in the analysed path set.
     * A layer that matches exclusively outside that set (e.g. a vendor
     * namespace such as `ClickHouseDB\**`, reachable only as a dependency
     * TARGET) would otherwise always show zero hits and be reported
     * `architecture.unreachable-layer` even while `architecture.layer-violation`
     * reports a real edge into it — a self-contradictory diagnostic pair.
     * Merging edge-side hits into the class-side count in {@see walk()}
     * fixes that without weakening unreachable-layer's typo-detection case:
     * a layer matching neither a class nor an edge end still gets zero hits.
     *
     * @return array{0: list<array{dependency: \Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency, fromMatch: LayerMatch, toMatch: LayerMatch}>, 1: array{sourceEdges: int, targetEdges: int, classes: array<string, string>}, 2: array<string, int>, 3: array<string, array<string, true>>}
     */
    private function collectEdgeEvidence(ArchitectureConfiguration $architecture, AnalysisContext $context): array
    {
        $forbidden = [];
        $sourceEdges = 0;
        $targetEdges = 0;
        $classes = [];
        $assignedHits = [];
        $matchedSymbols = [];

        $graph = $context->dependencyGraph;
        if ($graph === null) {
            return [$forbidden, ['sourceEdges' => 0, 'targetEdges' => 0, 'classes' => []], $assignedHits, $matchedSymbols];
        }

        $registry = $architecture->registry();
        foreach ($graph->getAllDependencies() as $dependency) {
            $fromMatches = $registry->resolveAll($dependency->sourceLogical());
            $toMatches = $registry->resolveAll($dependency->targetLogical());

            $matchedSymbols = self::tallyMatchedEnd($matchedSymbols, $fromMatches, $dependency->sourceLogical()->toCanonical());
            $matchedSymbols = self::tallyMatchedEnd($matchedSymbols, $toMatches, $dependency->targetLogical()->toCanonical());

            $fromMatch = self::tallyEnd($fromMatches, $dependency->sourceLogical()->toCanonical(), $dependency->sourceLogical()->toString(), $assignedHits, $classes, $sourceEdges);
            $toMatch = self::tallyEnd($toMatches, $dependency->targetLogical()->toCanonical(), $dependency->targetLogical()->toString(), $assignedHits, $classes, $targetEdges);

            if ($fromMatch === null || $toMatch === null) {
                continue;
            }

            if ($architecture->policy()->isAllowed($fromMatch->layerName, $toMatch->layerName, $dependency->type)) {
                continue;
            }

            $forbidden[] = ['dependency' => $dependency, 'fromMatch' => $fromMatch, 'toMatch' => $toMatch];
        }

        return [
            $forbidden,
            ['sourceEdges' => $sourceEdges, 'targetEdges' => $targetEdges, 'classes' => $classes],
            $assignedHits,
            $matchedSymbols,
        ];
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
