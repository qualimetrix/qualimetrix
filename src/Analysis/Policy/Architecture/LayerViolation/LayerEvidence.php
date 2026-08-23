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
     * @param array<string, array<string, true>> $matchedSymbols Layer name => set of canonical symbols
     *                                                           the layer matched at all, winning or not.
     * @param array<string, array<string, list<ShadowEntry>>> $shadowEvidence (assigned, shadowed) => evidence.
     * @param array{classes: array<string, string>, analysed: int} $unassigned What the analysed set left
     *                                                                         outside every declared layer, and how many class-like declarations the walk saw — the
     *                                                                         numerator and denominator `architecture.unassigned-class` reports. `classes` is empty
     *                                                                         when {@see LayerViolationOptions::collectsOutsideLayerEvidence()} found no consumer for
     *                                                                         it. One field rather than two because neither half answers anything alone.
     * @param array{sourceEdges: int, targetEdges: int, classes: array<string, string>} $coverageState
     */
    public function __construct(
        public ArchitectureConfiguration $architecture,
        public array $forbiddenEdges,
        public array $assignedHits,
        public array $matchedSymbols,
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
        return array_map(\count(...), $this->matchedSymbols);
    }
}
