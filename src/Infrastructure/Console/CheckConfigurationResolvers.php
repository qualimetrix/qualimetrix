<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Reporting\Contract\OutputFormatResolverInterface;
use Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusionsResolverInterface;

/** Resolves the run, finding exclusions and reporting format for check. */
final readonly class CheckConfigurationResolvers
{
    public function __construct(
        private RunConfigurationPreparation $runConfigurationPreparation,
        private ConfiguredFindingExclusionsResolverInterface $findingExclusionsResolver,
        private OutputFormatResolverInterface $outputFormatResolver,
    ) {}

    public function resolve(ConfigurationDocument $document): CheckResolvedConfiguration
    {
        return new CheckResolvedConfiguration(
            $this->runConfigurationPreparation->resolve($document),
            $this->findingExclusionsResolver->resolve($document),
            $this->outputFormatResolver->resolve($document),
        );
    }
}
