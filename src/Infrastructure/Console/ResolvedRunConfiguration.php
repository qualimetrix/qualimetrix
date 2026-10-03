<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfiguration;

/** Owner-accepted run, root-dependent cache and parallel values from one document. */
final readonly class ResolvedRunConfiguration
{
    public function __construct(
        public RunConfiguration $runConfiguration,
        public CacheConfiguration $cacheConfiguration,
        public ParallelConfiguration $parallelConfiguration,
    ) {}
}
