<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

interface MetricReachCatalogInterface
{
    /** Aggregate spellings inherit their base metric's reach; unknown keys are refused. */
    public function metricReach(string $metricKey): MetricReach;
}
