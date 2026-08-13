<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\ComputedMetrics\Contract;

use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinition;

/**
 * Applies health-dimension exclusions to resolved computed metric definitions.
 */
interface HealthFormulaExclusionInterface
{
    /**
     * @param list<ComputedMetricDefinition> $definitions
     * @param list<string> $excludedDimensions
     *
     * @return list<ComputedMetricDefinition>
     */
    public function applyExcludeHealth(array $definitions, array $excludedDimensions): array;
}
