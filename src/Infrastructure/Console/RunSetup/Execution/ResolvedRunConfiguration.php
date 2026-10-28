<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\RunSetup\Execution;

use Closure;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Core\FileTarget\FileIdentity;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfiguration;

/** Owner-accepted run, root-dependent cache and parallel values from one document. */
final readonly class ResolvedRunConfiguration
{
    /** @param Closure(?FileIdentity): ?string $fileReadAs */
    public function __construct(
        public RunConfiguration $runConfiguration,
        public CacheConfiguration $cacheConfiguration,
        public ParallelConfiguration $parallelConfiguration,
        private Closure $fileReadAs,
    ) {}

    /**
     * The file this run reads that has the given identity — a configuration
     * file, the Composer manifest or an analysed source — as a refusal names
     * it, or null.
     */
    public function fileReadAs(?FileIdentity $identity): ?string
    {
        return ($this->fileReadAs)($identity);
    }
}
