<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection\Metric;

use Qualimetrix\Core\Dependency\Dependency;
use Qualimetrix\Core\Metric\MetricBag;

/**
 * Output of a single file collection (metrics + dependencies).
 *
 * Used by CompositeCollector to return both metric and dependency data
 * from a single AST traversal.
 */
final readonly class CollectionOutput
{
    /**
     * @param MetricBag $metrics Collected file-level metrics
     * @param list<Dependency> $dependencies Collected dependencies
     */
    public function __construct(
        public MetricBag $metrics,
        public array $dependencies = [],
    ) {}
}
