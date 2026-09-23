<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerMatch;

/**
 * Everything one run's walk over the classes and the dependency graph
 * observed about the declared layers.
 *
 * It exists because two verdicts read the same walk: {@see LayerViolationRule}
 * judges the edges, {@see LayerDeclarationValidator} judges the declaration
 * itself. Before the split both lived in one `analyze()` and shared local
 * variables; a shared collector plus this value object is what replaces those
 * locals without walking the graph twice and without making either verdict
 * depend on the other running first.
 *
 * @phpstan-type ShadowEntry array{fqn: string, assignedCriterion: \Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterion, shadowedCriterion: \Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterion}
 * @phpstan-type ForbiddenEdge array{dependency: Dependency, fromMatch: LayerMatch, toMatch: LayerMatch}
 * @phpstan-type SymbolSets array{matched: array<string, array<string, true>>, excluded: array<string, array<string, true>>, unanswered: array<string, array<string, true>>, undecided: array<string, array<string, true>>, contended: array<string, array<string, true>>}
 */
final readonly class LayerEvidence
{
    /**
     * @param ArchitectureConfiguration $architecture The prepared configuration the walk read.
     * @param list<ForbiddenEdge> $forbiddenEdges Edges the allow-list rejects, in graph order.
     *                                            The allow-list test is a pure function of the
     *                                            policy and is applied during the walk so the
     *                                            graph need not be materialised; what the edge
     *                                            finding then *says* — severity, routing
     *                                            guidance — stays with the rule.
     * @param array<string, int> $assignedHits Layer name => number of classes and dependency-edge
     *                                         ends assigned to it.
     * @param SymbolSets $symbolSets
     *                               The per-layer symbol sets the walk records, as one field: `matched` is every canonical symbol
     *                               the layer's criteria matched at all, winning or not; `excluded` is every one its `exclude:`
     *                               clause removed after those criteria had already succeeded; `unanswered` is every one the
     *                               criteria matched while the clause could not be answered about it. One field rather than
     *                               adjacent parameters of the same type because they are columns of a single observation —
     *                               filled by the same tally helper and merged by the same merge. `excluded` is disjoint from
     *                               the other two, since an excluded symbol is not a member; `unanswered` is a subset of
     *                               `matched`. `undecided` is every symbol the layer could not answer about while it bore on
     *                               the symbol's assignment, and `contended` every symbol the layer could still own once the
     *                               run answers that — both straight from the registry's walk, never re-derived here. Read
     *                               through {@see matchedCounts()}, {@see excludedCounts()}, {@see unansweredExcludeCounts()},
     *                               {@see undecidedSymbolsByLayer()} and {@see reachedCounts()}.
     * @param array<string, array<string, list<ShadowEntry>>> $shadowEvidence (assigned, shadowed) => evidence.
     * @param array{classes: array<string, string>, analysed: int} $unassigned What the analysed set left
     *                                                                         outside every declared layer, and how many class-like declarations the walk saw — the
     *                                                                         numerator and denominator `architecture.unassigned-class` reports. `classes` is empty
     *                                                                         when {@see LayerEvidenceCollector::materializesUncovered()} found no consumer for
     *                                                                         it. One field rather than two because neither half answers anything alone.
     * @param array{sourceEdges: int, targetEdges: int, classes: array<string, string>, undecidable: array<string, string>, undecidableOutsidePaths: array<string, string>, doubted: array<string, string>, doubtedOutsidePaths: array<string, string>} $coverageState
     *                                                                                                                                                                                                                                                                 `undecidable` is the symbols no layer claims because some layer's criteria
     *                                                                                                                                                                                                                                                                 could not be answered about it at all — a chain that left the analysed set, or a symbol seen
     *                                                                                                                                                                                                                                                                 only as the far end of an edge. It travels beside the count rather than inside it, because
     *                                                                                                                                                                                                                                                                 subtracting it would hide the gap and folding it in would hide that a layer declared for it
     *                                                                                                                                                                                                                                                                 only guesses. `doubted` is disjoint from `classes`: symbols that ARE assigned while a layer
     *                                                                                                                                                                                                                                                                 bearing on the assignment went unanswered. Both are booked in every coverage mode, while the analysed share of `classes` is booked only when a consumer reads it. `undecidableOutsidePaths` and `doubtedOutsidePaths` are the subsets of `undecidable` and `doubted`
     *                                                                                                                                                                                                                                                                 the run did not analyse — dependency-edge ends — kept apart because what settles the doubt
     *                                                                                                                                                                                                                                                                 differs for them.
     */
    public function __construct(
        public ArchitectureConfiguration $architecture,
        public array $forbiddenEdges,
        public array $assignedHits,
        public array $symbolSets,
        public array $shadowEvidence,
        public array $unassigned,
        public array $coverageState,
    ) {}

    /**
     * Canonical key => display FQN for every analysed class outside all
     * declared layers.
     *
     * @return array<string, string>
     */
    public function uncoveredClasses(): array
    {
        return $this->unassigned['classes'];
    }

    public function analysedDeclarations(): int
    {
        return $this->unassigned['analysed'];
    }

    /**
     * @return array<string, int> layer name => number of DISTINCT symbols matched
     */
    public function matchedCounts(): array
    {
        return array_map(\count(...), $this->symbolSets['matched']);
    }

    /**
     * @return array<string, int> layer name => number of DISTINCT symbols its
     *                            `exclude:` clause removed
     */
    public function excludedCounts(): array
    {
        return array_map(\count(...), $this->symbolSets['excluded']);
    }

    /**
     * @return array<string, int> layer name => number of DISTINCT symbols the
     *                            layer's criteria matched while its `exclude:`
     *                            clause could not be answered about them
     */
    public function unansweredExcludeCounts(): array
    {
        return array_map(\count(...), $this->symbolSets['unanswered']);
    }

    /**
     * @return array<string, array<string, true>> layer name => the canonical
     *                                            symbols it could not answer
     *                                            about while it bore on their
     *                                            assignment, in declaration
     *                                            order, only layers with any
     */
    public function undecidedSymbolsByLayer(): array
    {
        $byLayer = [];
        foreach ($this->architecture->registry()->layerNames() as $layerName) {
            $symbols = $this->symbolSets['undecided'][$layerName] ?? [];
            if ($symbols !== []) {
                $byLayer[$layerName] = $symbols;
            }
        }

        return $byLayer;
    }

    /**
     * What `architecture.unreachable-layer` asks of a layer: how many
     * assignments it received — class and dependency-edge end alike — plus how
     * many symbols it could still own once the run answers what it could not
     * about them. Zero is the only value that says the layer owns nothing.
     *
     * @return array<string, int> layer name => count
     */
    public function reachedCounts(): array
    {
        $counts = $this->assignedHits;
        foreach ($this->symbolSets['contended'] as $layerName => $symbols) {
            $counts[$layerName] = ($counts[$layerName] ?? 0) + \count($symbols);
        }

        return $counts;
    }
}
