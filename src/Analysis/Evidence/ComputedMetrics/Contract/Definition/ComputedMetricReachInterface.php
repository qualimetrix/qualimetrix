<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReach;
use Qualimetrix\Core\Symbol\SymbolLevel;

interface ComputedMetricReachInterface
{
    public function reachAt(
        string $metricName,
        SymbolLevel $level,
        ComputedMetricDefinitionCatalogInterface $definitions,
    ): MetricReach;
}
