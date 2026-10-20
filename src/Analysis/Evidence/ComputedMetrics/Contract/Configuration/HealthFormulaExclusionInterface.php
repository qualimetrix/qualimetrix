<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration;

use Closure;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;

/**
 * Applies health-dimension exclusions to resolved computed metric definitions.
 */
interface HealthFormulaExclusionInterface
{
    /**
     * `$refuseOverall` builds the refusal of `health.overall` at a level whose
     * formula cannot be renormalized, from the level and the summary: only the
     * caller knows which layers wrote that formula and the exclusions.
     *
     * @param list<ComputedMetricDefinition> $definitions
     * @param list<string> $excludedDimensions known dimension names, bare or `health.`-prefixed
     * @param Closure(string, string): ConfigurationRefusal $refuseOverall
     *
     * @throws ConfigurationRefusal
     *
     * @return list<ComputedMetricDefinition>
     */
    public function applyExcludeHealth(array $definitions, array $excludedDimensions, Closure $refuseOverall): array;
}
