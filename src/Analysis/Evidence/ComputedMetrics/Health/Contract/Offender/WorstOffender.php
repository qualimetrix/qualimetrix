<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\WorstOffenderEvidence;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * A namespace or class identified as a worst offender in the analysis.
 */
final readonly class WorstOffender
{
    public SymbolPath $symbolPath;
    public ?RelativePath $file;
    public int $violationCount;
    public int $classCount;
    /** @var array<string, int|float> */
    public array $metrics;
    /** @var array<string, float> */
    public array $healthScores;
    public ?float $violationDensity;

    /** @param array{float, float} $overallThresholds */
    public function __construct(
        public MetricSubject $subject,
        public float $healthOverall,
        public string $label,
        public string $reason,
        WorstOffenderEvidence $evidence,
        public array $overallThresholds,
    ) {
        $this->symbolPath = $subject->toSymbolPath();
        $this->file = $subject->declarationPath()?->file;
        $this->violationCount = $evidence->violationCount;
        $this->classCount = $evidence->classCount;
        $this->metrics = $evidence->metrics;
        $this->healthScores = $evidence->healthScores;
        $this->violationDensity = $evidence->violationDensity;
    }

    /** @param array{float, float} $overallThresholds */
    public static function fromEvidence(
        MetricSubject $subject,
        float $healthOverall,
        string $label,
        string $reason,
        WorstOffenderEvidence $evidence,
        array $overallThresholds,
    ): self {
        return new self(
            $subject,
            $healthOverall,
            $label,
            $reason,
            $evidence,
            $overallThresholds,
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
     * @param list<self> $offenders
     *
     * @return list<self>
     */
    public static function rank(array $offenders, RankBy $rankBy): array
    {
        usort($offenders, static function (self $a, self $b) use ($rankBy): int {
            $primary = $rankBy === RankBy::Density
                ? (($b->violationDensity ?? -1.0) <=> ($a->violationDensity ?? -1.0))
                : ($a->healthOverall <=> $b->healthOverall);

            if ($primary !== 0) {
                return $primary;
            }
            $canonical = $a->symbolPath->toCanonical() <=> $b->symbolPath->toCanonical();

            return $canonical !== 0 ? $canonical : ($a->subject->toCanonical() <=> $b->subject->toCanonical());
        });

        return $offenders;
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
