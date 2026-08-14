<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;

interface ComputedMetricConfiguratorInterface
{
    public function configure(ConfigurationDocument $document): void;
}
