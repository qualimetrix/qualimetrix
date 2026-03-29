<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection;

use Qualimetrix\Core\Suppression\Suppression;
use Qualimetrix\Core\Suppression\ThresholdDiagnostic;
use Qualimetrix\Core\Suppression\ThresholdOverride;

/**
 * Result of the collection phase.
 *
 * Contains summary information about files processed during collection.
 * Dependencies are returned separately (via CollectionPhaseOutput) because they have
 * a shorter lifecycle — they are consumed during graph building and should not
 * persist for the rest of the pipeline.
 */
final readonly class CollectionResult
{
    /**
     * @param int $filesAnalyzed Number of files successfully analyzed
     * @param int $filesSkipped Number of files skipped due to errors
     * @param array<string, list<Suppression>> $suppressions Per-file suppression tags (file => suppressions)
     * @param array<string, list<ThresholdOverride>> $thresholdOverrides Per-file threshold overrides (file => overrides)
     * @param array<string, list<ThresholdDiagnostic>> $thresholdDiagnostics Per-file diagnostics for invalid @qmx-threshold annotations
     */
    public function __construct(
        public int $filesAnalyzed,
        public int $filesSkipped,
        public array $suppressions = [],
        public array $thresholdOverrides = [],
        public array $thresholdDiagnostics = [],
    ) {}

    /**
     * Returns total number of files processed (analyzed + skipped).
     */
    public function totalFiles(): int
    {
        return $this->filesAnalyzed + $this->filesSkipped;
    }

    /**
     * Returns whether any files failed to process.
     */
    public function hasErrors(): bool
    {
        return $this->filesSkipped > 0;
    }
}
