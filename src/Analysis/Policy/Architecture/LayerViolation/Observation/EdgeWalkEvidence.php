<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation\Observation;

/**
 * What {@see LayerEvidenceCollector}'s walk over the dependency graph
 * observed, before it is merged with the class walk.
 *
 * @phpstan-import-type SymbolSets from LayerEvidence
 */
final readonly class EdgeWalkEvidence
{
    /**
     * @param list<ForbiddenEdge> $forbiddenEdges Edges the allow-list rejects, in graph order.
     * @param array{sourceEdges: int, targetEdges: int, classes: array<string, string>, undecidable: array<string, string>, doubted: array<string, string>} $coverageState
     * @param array<string, int> $assignedHits Layer name => dependency-edge ends assigned to it.
     * @param SymbolSets $symbolSets Layer name => set of canonical edge ends, per column; see
     *                               {@see LayerEvidence::__construct()} for what each column holds.
     */
    public function __construct(
        public array $forbiddenEdges,
        public array $coverageState,
        public array $assignedHits,
        public array $symbolSets,
    ) {}
}
