<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Metadata;

interface HealthMetricMetadataProviderInterface
{
    public function metadata(): HealthMetricMetadataCollection;
}
