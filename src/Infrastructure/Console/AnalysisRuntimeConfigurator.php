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
use Qualimetrix\Core\Pattern\NamespacePattern;
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

    private function resolveArchitecturePolicy(ConfigurationDocument $document): ResolvedArchitecturePolicyInterface
    {
        return $this->architecturePolicyConfigurator->resolve($document);
    }

    private function resolveComputedMetrics(ConfigurationDocument $document): ResolvedComputedMetricDefinitions
    {
        return $this->computedMetricConfigurator->resolve($document);
    }

    /** @return list<NamespacePattern> */
    public function resolveCoupling(ConfigurationDocument $document): array
    {
        return $this->couplingConfigurator->resolve($document);
    }

    private function resolveLcom(FindingConfiguration $findingConfiguration): LcomCollectionConfiguration
    {
        return $this->lcomConfigurationResolver->resolve($findingConfiguration);
    }

    private function resolveRuleChannels(
        InputInterface $input,
        FindingConfiguration $findingConfiguration,
        ResolvedComputedMetricDefinitions $definitions,
    ): RuleChannelRegistryInterface {
        return $this->ruleInputValidator->validate($input, $findingConfiguration, $definitions);
    }

    public function prepare(ConfigurationDocument $document, FindingConfiguration $findingConfiguration, InputInterface $input): PreparedAnalysisRuntimeConfiguration
    {
        $architecturePolicy = $this->resolveArchitecturePolicy($document);
        $computedMetrics = $this->resolveComputedMetrics($document);
        $lcomConfiguration = $this->resolveLcom($findingConfiguration);
        ProfilePresenter::refuseImpossibleExport($input);
        $channels = $this->resolveRuleChannels($input, $findingConfiguration, $computedMetrics);
        $frameworkNamespaces = $this->resolveCoupling($document);

        return new PreparedAnalysisRuntimeConfiguration($findingConfiguration, $lcomConfiguration, $architecturePolicy, $computedMetrics, $frameworkNamespaces, $channels);
    }

    public function replace(PreparedAnalysisRuntimeConfiguration $configuration): void
    {
        $this->architecturePolicyConfigurator->replace($configuration->architecturePolicy);
        $this->computedMetricConfigurator->replace($configuration->computedMetrics);
        $this->couplingConfigurator->replace($configuration->frameworkNamespaces);
        $this->ruleOptionsRegistry->replace($configuration->findingConfiguration);
        $this->lcomConfigurationStore->replace($configuration->lcomConfiguration);
    }

    /**
     * @param list<NamespacePattern> $frameworkNamespaces
     */
    public function replaceCoupling(array $frameworkNamespaces): void
    {
        $this->couplingConfigurator->replace($frameworkNamespaces);
    }

    public function captureExcludedFindings(): void
    {
        $this->ruleOptionsRegistry->captureExcludedFindings();
    }

    /** Clears state that must never leak into logger setup or the next run. */
    public function resetRunState(): void
    {
        $this->ruleOptionsRegistry->resetRuntimeState();
        $this->lcomConfigurationStore->reset();
    }
}
