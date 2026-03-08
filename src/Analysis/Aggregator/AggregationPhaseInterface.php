<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Aggregator;

use AiMessDetector\Core\Metric\MetricDefinition;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;

interface AggregationPhaseInterface
{
    /**
     * @param list<MetricDefinition> $definitions
     */
    public function aggregate(MetricRepositoryInterface $repository, array $definitions): void;
}
