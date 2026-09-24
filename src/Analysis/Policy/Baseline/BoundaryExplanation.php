<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

/**
 * What `bin/qmx baseline:explain <symbol>` prints, before printing: every
 * boundary bearing on the symbol, one per identity the file or the run names (ADR 0017). This type carries data only — formatting it is
 * the command's job, not {@see BoundaryExplanationService}'s.
 */
final readonly class BoundaryExplanation
{
    /**
     * @param string $subjectKey the opaque canonical metric-subject key
     *                           this explanation is about
     * @param list<EffectiveBoundary> $boundaries one entry per identity found relevant —
     *                                            every baseline entry and every currently-firing
     *                                            channel for this symbol, narrowed to a single
     *                                            channel when the caller asked for one
     * @param list<InertBaselineEntry> $unidentifiedEntries lines of the file about this subject
     *                                                      whose identity could not be read, so
     *                                                      no boundary exists for them
     */
    public function __construct(
        public string $subjectKey,
        public array $boundaries,
        public BoundaryExplanationStatus $status,
        public array $unidentifiedEntries,
    ) {}
}
