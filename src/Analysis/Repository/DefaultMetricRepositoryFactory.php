<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Repository;

use AiMessDetector\Core\Metric\MetricRepositoryInterface;

/**
 * Default factory that creates InMemoryMetricRepository instances.
 */
final class DefaultMetricRepositoryFactory implements MetricRepositoryFactoryInterface
{
    public function create(): MetricRepositoryInterface
    {
        return new InMemoryMetricRepository();
    }
}
