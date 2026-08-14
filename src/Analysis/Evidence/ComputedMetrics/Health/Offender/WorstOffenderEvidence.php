<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender;

final readonly class WorstOffenderEvidence
{
    /**
     * @param array<string, int|float> $metrics
     * @param array<string, float> $healthScores
     */
    public function __construct(
        public int $violationCount,
        public int $classCount,
        public array $metrics = [],
        public array $healthScores = [],
        public ?float $violationDensity = null,
    ) {}

}
