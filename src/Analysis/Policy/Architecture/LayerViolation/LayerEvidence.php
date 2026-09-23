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
     * @param array{matched: array<string, array<string, true>>, excluded: array<string, array<string, true>>} $symbolSets
     *                                                                                                                     The two per-layer symbol sets the walk records, as one field: `matched` is every canonical
     *                                                                                                                     symbol the layer's criteria matched at all, winning or not; `excluded` is every one its
     *                                                                                                                     `exclude:` clause removed after those criteria had already succeeded. One field rather than
     *                                                                                                                     two adjacent parameters of the same type because they are two columns of a single
     *                                                                                                                     observation — filled by the same tally helper, merged by the same merge, and disjoint by
     *                                                                                                                     construction, since an excluded symbol is not a member. Read through {@see matchedCounts()}
     *                                                                                                                     and {@see excludedCounts()}.
     * @param array<string, array<string, list<ShadowEntry>>> $shadowEvidence (assigned, shadowed) => evidence.
     * @param array{classes: array<string, string>, analysed: int} $unassigned What the analysed set left
     *                                                                         outside every declared layer, and how many class-like declarations the walk saw — the
     *                                                                         numerator and denominator `architecture.unassigned-class` reports. `classes` is empty
     *                                                                         when {@see LayerEvidenceCollector::materializesUncovered()} found no consumer for
     *                                                                         it. One field rather than two because neither half answers anything alone.
     * @param array{sourceEdges: int, targetEdges: int, classes: array<string, string>, undecidable: array<string, string>} $coverageState
     *                                                                                                                                     `undecidable` is the subset of `classes` that no layer claims because some layer's
     *                                                                                                                                     criteria could not be answered about it at all — a chain that left the analysed set, or
     *                                                                                                                                     a symbol seen only as the far end of an edge. It travels beside the count rather than
     *                                                                                                                                     inside it, because subtracting it would hide the gap and folding it in would tell the
     *                                                                                                                                     author to declare a layer that cannot help.
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
}
