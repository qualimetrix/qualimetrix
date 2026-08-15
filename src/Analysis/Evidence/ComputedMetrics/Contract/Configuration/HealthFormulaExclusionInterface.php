<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;

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
