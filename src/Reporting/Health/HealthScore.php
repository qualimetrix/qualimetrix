<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Health;

/**
 * One health dimension with its score and decomposition.
 */
final readonly class HealthScore
{
    /**
     * @param list<DecompositionItem> $decomposition
     */
    public function __construct(
        public string $name,
        public ?float $score,
        public string $label,
        public float $warningThreshold,
        public float $errorThreshold,
        public array $decomposition = [],
    ) {}
}
