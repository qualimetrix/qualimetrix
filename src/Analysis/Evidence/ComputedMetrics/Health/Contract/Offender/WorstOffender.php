<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\WorstOffenderEvidence;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * A namespace or class identified as a worst offender in the analysis.
 */
final readonly class WorstOffender
{
    private WorstOffenderEvidence $evidence;
    public int $violationCount;
    public int $classCount;
    /** @var array<string, int|float> */
    public array $metrics;
    /** @var array<string, float> */
    public array $healthScores;
    public ?float $violationDensity;

    public function __construct(
        public SymbolPath $symbolPath,
        public ?RelativePath $file,
        public float $healthOverall,
        public string $label,
        public string $reason,
        WorstOffenderEvidence $evidence,
    ) {
        $this->evidence = $evidence;
        $this->violationCount = $evidence->violationCount;
        $this->classCount = $evidence->classCount;
        $this->metrics = $evidence->metrics;
        $this->healthScores = $evidence->healthScores;
        $this->violationDensity = $evidence->violationDensity;
    }

    public static function fromEvidence(
        SymbolPath $symbolPath,
        ?RelativePath $file,
        float $healthOverall,
        string $label,
        string $reason,
        WorstOffenderEvidence $evidence,
    ): self {
        return new self(
            $symbolPath,
            $file,
            $healthOverall,
            $label,
            $reason,
            $evidence,
        );
    }

    /**
     * Wire-surface string of the file path; empty string when this offender has no file (namespace-level).
     */
    public function pathString(): string
    {
        return $this->file?->value() ?? '';
    }

    /**
     * Re-ranks offenders by finding density (descending) when requested.
     *
     * Falls back to canonical path for stable ordering among equal densities.
     * Returns the original list unchanged when rank-by is not 'density'.
     *
     * @param list<self> $offenders
     *
     * @return list<self>
     */
    public static function rankByDensity(array $offenders, string $rankBy): array
    {
        if ($rankBy !== 'density') {
            return $offenders;
        }

        $sorted = $offenders;
        usort($sorted, static fn(self $a, self $b): int => (($b->evidence->violationDensity ?? -1.0) <=> ($a->evidence->violationDensity ?? -1.0)) !== 0 ? (($b->evidence->violationDensity ?? -1.0) <=> ($a->evidence->violationDensity ?? -1.0))
                : ($a->symbolPath->toCanonical() <=> $b->symbolPath->toCanonical()));

        return $sorted;
    }

    /**
     * Computes finding density as findings per 100 LOC.
     *
     * Returns 0.0 when there are no findings, null when LOC is unavailable or zero.
     */
    public static function computeViolationDensity(
        int $violationCount,
        int|float|null $loc,
    ): ?float {
        if ($violationCount === 0) {
            return 0.0;
        }

        if ($loc === null || (int) $loc <= 0) {
            return null;
        }

        return round($violationCount / (float) $loc * 100, 1);
    }
}
