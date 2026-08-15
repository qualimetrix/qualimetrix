<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfiguration;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfigurationResolverInterface;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfigurationStoreInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\Coupling\Contract\Configuration\CouplingConfiguratorInterface;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePolicyConfiguratorInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ResolvedArchitecturePolicyInterface;
use Symfony\Component\Console\Input\InputInterface;

/** Configures the analysis engine's per-run rule, collector, and feature state. */
final readonly class AnalysisRuntimeConfigurator
{
    public function __construct(
        private RuleConfigurationInterface $ruleOptionsRegistry,
        private LcomCollectionConfigurationResolverInterface $lcomConfigurationResolver,
        private LcomCollectionConfigurationStoreInterface $lcomConfigurationStore,
        private ArchitecturePolicyConfiguratorInterface $architecturePolicyConfigurator,
        private ComputedMetricConfiguratorInterface $computedMetricConfigurator,
        private CouplingConfiguratorInterface $couplingConfigurator,
        private RuleInputValidator $ruleInputValidator,
    ) {}

    public function resolveArchitecturePolicy(ConfigurationDocument $document): ResolvedArchitecturePolicyInterface
    {
        return $this->architecturePolicyConfigurator->resolve($document);
    }

    public function resolveComputedMetrics(ConfigurationDocument $document): ResolvedComputedMetricDefinitions
    {
        return $this->computedMetricConfigurator->resolve($document);
    }

    /** @return list<string> */
    public function resolveCoupling(ConfigurationDocument $document): array
    {
        return $this->couplingConfigurator->resolve($document);
    }

    public function resolveLcom(FindingConfiguration $findingConfiguration): LcomCollectionConfiguration
    {
        return $this->lcomConfigurationResolver->resolve($findingConfiguration);
    }

    public function resolveRuleChannels(
        InputInterface $input,
        FindingConfiguration $findingConfiguration,
        ResolvedComputedMetricDefinitions $definitions,
    ): RuleChannelRegistryInterface {
        return $this->ruleInputValidator->validate($input, $findingConfiguration, $definitions);
    }

    /**
     * @param list<string> $frameworkNamespaces
     */
    public function replace(
        FindingConfiguration $findingConfiguration,
        LcomCollectionConfiguration $lcomConfiguration,
        ResolvedArchitecturePolicyInterface $architecturePolicy,
        ResolvedComputedMetricDefinitions $computedMetrics,
        array $frameworkNamespaces,
        RuleChannelRegistryInterface $channels,
    ): void {
        $this->architecturePolicyConfigurator->replace($architecturePolicy);
        $this->computedMetricConfigurator->replace($computedMetrics);
        $this->couplingConfigurator->replace($frameworkNamespaces);
        $this->ruleOptionsRegistry->replace($findingConfiguration);
        $this->lcomConfigurationStore->replace($lcomConfiguration);
        $this->ruleInputValidator->replaceChannels($channels);
    }

    public function captureExcludedViolations(): void
    {
        $this->ruleOptionsRegistry->captureExcludedViolations();
    }

    /** Clears state that must never leak into logger setup or the next run. */
    public function resetRunState(): void
    {
        $this->ruleOptionsRegistry->resetRuntimeState();
        $this->lcomConfigurationStore->reset();
        $this->ruleInputValidator->resetChannels();
    }
}
