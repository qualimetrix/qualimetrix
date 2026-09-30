<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfigurationResolverInterface;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationResolverInterface;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationResolverInterface;

/** Resolves one run and its dependent runtime values before any stores commit. */
final readonly class RunConfigurationPreparation
{
    public function __construct(
        private RunConfigurationResolverInterface $runConfigurationResolver,
        private CacheConfigurationResolverInterface $cacheConfigurationResolver,
        private ParallelConfigurationResolverInterface $parallelConfigurationResolver,
    ) {}

    public function resolve(ConfigurationDocument $document): ResolvedRunConfiguration
    {
        $run = $this->runConfigurationResolver->resolve($document);

        return new ResolvedRunConfiguration(
            $run,
            $this->cacheConfigurationResolver->resolve($document, $run->projectRoot),
            $this->parallelConfigurationResolver->resolve($document),
        );
    }
}
