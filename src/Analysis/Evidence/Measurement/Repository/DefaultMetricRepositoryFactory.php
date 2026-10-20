<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Repository;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryFactoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;

/** Creates fresh native stores for a finite measurement definition set. */
final class DefaultMetricRepositoryFactory implements MetricRepositoryFactoryInterface
{
    public function create(array $definitions = []): MetricRepositoryInterface
    {
        return new InMemoryMetricRepository($definitions);
    }
}
