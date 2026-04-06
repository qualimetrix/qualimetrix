<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Health;

use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * A namespace or class identified as a worst offender in the analysis.
 */
final readonly class WorstOffender
{
    /**
     * @param array<string, int|float> $metrics
     * @param array<string, float> $healthScores
     * @param float|null $violationDensity Violations per 100 LOC (null when LOC unavailable or zero)
     */
    public function __construct(
        public SymbolPath $symbolPath,
        public ?string $file,
        public float $healthOverall,
        public string $label,
        public string $reason,
        public int $violationCount,
        public int $classCount,
        public array $metrics = [],
        public array $healthScores = [],
        public ?float $violationDensity = null,
    ) {}

    /**
     * Re-ranks offenders by violation density (descending) when requested.
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
        usort($sorted, static fn(self $a, self $b): int => (($b->violationDensity ?? -1.0) <=> ($a->violationDensity ?? -1.0)) !== 0 ? (($b->violationDensity ?? -1.0) <=> ($a->violationDensity ?? -1.0))
                : ($a->symbolPath->toCanonical() <=> $b->symbolPath->toCanonical()));

        return $sorted;
    }

    /**
     * Computes violation density as violations per 100 LOC.
     *
     * Returns 0.0 when there are no violations, null when LOC is unavailable or zero.
     */
    public static function computeViolationDensity(
        int $violationCount,
        MetricBag $metrics,
        string $locKey,
    ): ?float {
        if ($violationCount === 0) {
            return 0.0;
        }

        $loc = $metrics->get($locKey);

        if ($loc === null || (int) $loc <= 0) {
            return null;
        }

        return round($violationCount / (float) $loc * 100, 1);
    }
}
