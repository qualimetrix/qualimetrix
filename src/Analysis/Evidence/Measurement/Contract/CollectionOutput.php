<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;

final readonly class CollectionOutput
{
    /** @param list<Dependency> $dependencies */
    public function __construct(
        public MetricBag $metrics,
        public array $dependencies = [],
    ) {}
}
