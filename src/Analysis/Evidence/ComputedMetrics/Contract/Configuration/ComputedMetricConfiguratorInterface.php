<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;

interface ComputedMetricConfiguratorInterface
{
    public function resolve(ConfigurationDocument $document): ResolvedComputedMetricDefinitions;

    public function replace(ResolvedComputedMetricDefinitions $definitions): void;
}
