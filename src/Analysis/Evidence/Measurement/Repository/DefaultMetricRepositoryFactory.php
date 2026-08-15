<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Repository;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryFactoryInterface;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;

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
