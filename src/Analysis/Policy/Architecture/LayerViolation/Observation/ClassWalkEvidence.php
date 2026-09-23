<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation\Observation;

/**
 * What {@see LayerEvidenceCollector}'s walk over the analysed classes
 * observed, before it is merged with the dependency-edge walk.
 */
final readonly class ClassWalkEvidence
{
    /**
     * @param array<string, int> $assignedHits Layer name => classes assigned to it.
     * @param array<string, array<string, true>> $matchedSymbols Layer name => set of canonical classes its criteria matched.
     * @param array<string, array<string, true>> $excludedSymbols Layer name => set of canonical classes its `exclude:` removed.
     * @param array<string, array<string, list<ShadowedClass>>> $shadowEvidence (assigned, shadowed) => evidence.
     * @param array<string, string> $uncoveredClasses Canonical key => display FQN of every class outside all layers.
     */
    public function __construct(
        public array $assignedHits,
        public array $matchedSymbols,
        public array $excludedSymbols,
        public array $shadowEvidence,
        public array $uncoveredClasses,
        public int $analysedDeclarations,
    ) {}
}
