<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\RunSetup;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Configuration\RuleOptionsBuild;
use Qualimetrix\Analysis\Finding\Contract\Selection\RuleEnablementResolver;
use Qualimetrix\Analysis\Policy\Architecture\Contract\UnassignedClassLayerRequirementInterface;
use Qualimetrix\Infrastructure\Rule\Contract\RuleChannelSnapshotFactoryInterface;

/** Fail-closed validation for all rule selectors accepted by CLI adapters. */
final readonly class FindingConfigurationPreparation
{
    public function __construct(
        private RuleChannelSnapshotFactoryInterface $ruleChannelSnapshotFactory,
        private RuleOptionsBuild $optionsBuild,
        private ComputedMetricConfiguratorInterface $computedMetricConfigurator,
        private RuleEnablementResolver $enablementResolver,
        private UnassignedClassLayerRequirementInterface $unassignedClassLayerRequirement,
    ) {}

    public function resolve(ConfigurationDocument $document): FindingConfiguration
    {
        $configuration = FindingConfiguration::fromDocument($document);
        $channels = $this->ruleChannelSnapshotFactory->snapshot($this->computedMetricConfigurator->resolve($document));
        $stated = $this->enablementResolver->decide($configuration->document, $channels);
        $configuration = $configuration->withChannelUniverse($channels)->withDiagnostics($stated->diagnostics());
        $options = $this->optionsBuild->build($configuration, $stated);
        $configuration = $configuration->withResolvedOptions($options)->withEnablement($this->enablementResolver->conclude($stated, $options));
        $this->unassignedClassLayerRequirement->assertSatisfied($configuration);
        return $configuration;
    }

}
