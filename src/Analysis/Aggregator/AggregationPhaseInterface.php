<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Aggregator;

use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Core\Metric\MetricDefinition;

interface AggregationPhaseInterface
{
    /**
     * @param list<MetricDefinition> $definitions
     */
    public function aggregate(InMemoryMetricRepository $repository, array $definitions): void;
}
