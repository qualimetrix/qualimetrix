<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;

interface MeasurementAggregationInterface
{
    public function aggregate(
        MetricRepositoryInterface $repository,
        DependencyGraphInterface $dependencies,
    ): NamespaceTree;
}
