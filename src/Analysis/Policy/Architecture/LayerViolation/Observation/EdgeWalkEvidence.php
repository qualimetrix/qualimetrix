<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation\Observation;

/**
 * What {@see LayerEvidenceCollector}'s walk over the dependency graph
 * observed, before it is merged with the class walk.
 */
final readonly class EdgeWalkEvidence
{
    /**
     * @param list<ForbiddenEdge> $forbiddenEdges Edges the allow-list rejects, in graph order.
     * @param array{sourceEdges: int, targetEdges: int, classes: array<string, string>} $coverageState
     * @param array<string, int> $assignedHits Layer name => dependency-edge ends assigned to it.
     * @param array<string, array<string, true>> $matchedSymbols Layer name => set of canonical edge ends its criteria matched.
     * @param array<string, array<string, true>> $excludedSymbols Layer name => set of canonical edge ends its `exclude:` removed.
     */
    public function __construct(
        public array $forbiddenEdges,
        public array $coverageState,
        public array $assignedHits,
        public array $matchedSymbols,
        public array $excludedSymbols,
    ) {}
}
