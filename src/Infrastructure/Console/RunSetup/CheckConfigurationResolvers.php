<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\RunSetup;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Reporting\Contract\OutputFormatResolverInterface;
use Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusionsResolverInterface;

/** Resolves accepted run, exclusions, output and Finding configuration for check. */
final readonly class CheckConfigurationResolvers
{
    public function __construct(
        private RunConfigurationPreparation $runConfigurationPreparation,
        private ConfiguredFindingExclusionsResolverInterface $findingExclusionsResolver,
        private OutputFormatResolverInterface $outputFormatResolver,
        private FindingConfigurationPreparation $findingConfigurationPreparation,
    ) {}

    public function resolve(ConfigurationDocument $document): CheckResolvedConfiguration
    {
        $run = $this->runConfigurationPreparation->resolve($document);
        $exclusions = $this->findingExclusionsResolver->resolve($document);
        $outputFormat = $this->outputFormatResolver->resolve($document);
        $findingConfiguration = $this->findingConfigurationPreparation->resolve($document);
        return new CheckResolvedConfiguration($run, $exclusions, $outputFormat, $findingConfiguration);
    }
}
