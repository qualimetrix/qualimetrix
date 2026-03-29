<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Health;

/**
 * A class identified as a worst contributor for a health dimension.
 *
 * Stores the class name and the metric values that make it a worst contributor
 * (e.g., TCC=30.0, LCOM=5 for the cohesion dimension).
 */
final readonly class HealthContributor
{
    /**
     * @param string $className Short class name (without namespace)
     * @param string $symbolPath Canonical symbol path for drill-down
     * @param array<string, float|int> $metricValues Metric key => value pairs (e.g., ['tcc' => 30.0, 'lcom' => 5])
     */
    public function __construct(
        public string $className,
        public string $symbolPath,
        public array $metricValues,
    ) {}
}
