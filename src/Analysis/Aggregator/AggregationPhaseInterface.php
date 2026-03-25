<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Aggregator;

use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;

interface AggregationPhaseInterface
{
    /**
     * @param list<MetricDefinition> $definitions
     */
    public function aggregate(MetricRepositoryInterface $repository, array $definitions): void;
}
