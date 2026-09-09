<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfigurationResolverInterface;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationResolverInterface;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationResolverInterface;
use Qualimetrix\Reporting\Contract\OutputFormatResolverInterface;
use Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusionsResolverInterface;

/**
 * The run configuration, the cache configuration, the parallel configuration,
 * the finding exclusions, and the output format — five values `check` reads
 * off one resolved document, through five single-purpose resolvers that have
 * no reason to talk to each other except that {@see CacheConfigurationResolverInterface}
 * needs the run configuration's project root.
 *
 * Grouped here, and only here, so `CheckCommand`'s constructor takes one
 * collaborator for "resolve the document" instead of five — the fifth
 * resolver over the code-smell.constructor-overinjection threshold was what
 * made the split worth doing; the other four could have stayed as they were.
 */
final readonly class CheckConfigurationResolvers
{
    public function __construct(
        private RunConfigurationResolverInterface $runConfigurationResolver,
        private CacheConfigurationResolverInterface $cacheConfigurationResolver,
        private ParallelConfigurationResolverInterface $parallelConfigurationResolver,
        private ConfiguredFindingExclusionsResolverInterface $findingExclusionsResolver,
        private OutputFormatResolverInterface $outputFormatResolver,
    ) {}

    public function resolve(ConfigurationDocument $document): CheckResolvedConfiguration
    {
        $runConfiguration = $this->runConfigurationResolver->resolve($document);

        return new CheckResolvedConfiguration(
            $runConfiguration,
            $this->cacheConfigurationResolver->resolve($document, $runConfiguration->projectRoot),
            $this->parallelConfigurationResolver->resolve($document),
            $this->findingExclusionsResolver->resolve($document),
            $this->outputFormatResolver->resolve($document),
        );
    }
}
