<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Health;

use AiMessDetector\Core\Symbol\SymbolPath;

/**
 * A namespace or class identified as a worst offender in the analysis.
 */
final readonly class WorstOffender
{
    /**
     * @param array<string, int|float> $metrics
     * @param array<string, float> $healthScores
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
    ) {}
}
