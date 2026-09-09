<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfiguration;
use Qualimetrix\Reporting\Contract\OutputFormat;
use Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusions;

/**
 * The five values {@see CheckConfigurationResolvers} reads off one resolved
 * `ConfigurationDocument`, bundled so `check` takes one collaborator for
 * "resolve the document" instead of five.
 */
final readonly class CheckResolvedConfiguration
{
    public function __construct(
        public RunConfiguration $runConfiguration,
        public CacheConfiguration $cacheConfiguration,
        public ParallelConfiguration $parallelConfiguration,
        public ConfiguredFindingExclusions $findingExclusions,
        public OutputFormat $outputFormat,
    ) {}
}
