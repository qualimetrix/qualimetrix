<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation\Observation;

/**
 * What {@see LayerEvidenceCollector}'s walk over the analysed classes
 * observed, before it is merged with the dependency-edge walk.
 *
 * @phpstan-import-type SymbolSets from LayerEvidence
 */
final readonly class ClassWalkEvidence
{
    /**
     * @param array<string, int> $assignedHits Layer name => classes assigned to it.
     * @param SymbolSets $symbolSets Layer name => set of canonical classes, per column; see
     *                               {@see LayerEvidence::__construct()} for what each column holds.
     * @param array<string, array<string, list<ShadowedClass>>> $shadowEvidence (assigned, shadowed) => evidence.
     * @param array<string, string> $uncoveredClasses Canonical key => display FQN of every class outside all layers.
     * @param array<string, string> $undecidableClasses Canonical key => display FQN of every analysed class no layer
     *                                                  claims while a layer bearing on it went unanswered.
     * @param array<string, string> $doubtedClasses Canonical key => display FQN of every analysed class that is
     *                                              assigned while a layer bearing on the assignment went unanswered.
     */
    public function __construct(
        public array $assignedHits,
        public array $symbolSets,
        public array $shadowEvidence,
        public array $uncoveredClasses,
        public int $analysedDeclarations,
        public array $undecidableClasses,
        public array $doubtedClasses,
    ) {}
}
