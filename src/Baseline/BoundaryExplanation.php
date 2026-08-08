<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

/**
 * What `bin/qmx baseline:explain <symbol>` prints, before printing: every
 * boundary bearing on the symbol, one per applicable identity (ADR 0017). This type carries data only — formatting it is
 * the command's job, not {@see BoundaryExplanationService}'s.
 */
final readonly class BoundaryExplanation
{
    /**
     * @param string $symbolKey the canonical symbol key ({@see \Qualimetrix\Core\Symbol\SymbolPath::toCanonical()})
     *                          this explanation is about
     * @param list<EffectiveBoundary> $boundaries one entry per identity found relevant —
     *                                            every baseline entry and every currently-firing
     *                                            channel for this symbol, narrowed to a single
     *                                            channel when the caller asked for one
     */
    public function __construct(
        public string $symbolKey,
        public array $boundaries,
        public BoundaryExplanationStatus $status,
    ) {}
}
