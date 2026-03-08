<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Repository;

use AiMessDetector\Core\Metric\MetricRepositoryInterface;

/**
 * Factory for creating fresh MetricRepository instances.
 *
 * Each call to create() returns a new empty repository,
 * ensuring analysis runs don't share mutable state.
 */
interface MetricRepositoryFactoryInterface
{
    public function create(): MetricRepositoryInterface;
}
