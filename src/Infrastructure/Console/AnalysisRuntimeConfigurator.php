<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Lifecycle\AnalysisLifecycleHookInterface;
use Qualimetrix\Configuration\ComputedMetricsConfigResolver;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Configuration\Pipeline\ResolvedConfiguration;
use Qualimetrix\Configuration\RuleOptionsParserFactory;
use Qualimetrix\Configuration\RuleOptionsRegistry;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use Qualimetrix\Core\Coupling\FrameworkNamespaces;
use Qualimetrix\Core\Coupling\FrameworkNamespacesHolder;
use Qualimetrix\Core\Metric\CollectorConfigHolder;
use Qualimetrix\Core\Violation\RuleExclusionCaptureHolder;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Symfony\Component\Console\Input\InputInterface;

/** Configures the analysis engine's per-run rule, collector, and feature state. */
final readonly class AnalysisRuntimeConfigurator
{
    /** @param iterable<AnalysisLifecycleHookInterface> $lifecycleHooks */
    public function __construct(
        private ConfigurationProviderInterface $configurationProvider,
        private RuleOptionsRegistry $ruleOptionsRegistry,
        private RuleRegistryInterface $ruleRegistry,
        private CacheFactory $cacheFactory,
        private ComputedMetricsConfigResolver $computedMetricsResolver,
        private FrameworkNamespacesHolder $frameworkNamespacesHolder,
        private iterable $lifecycleHooks,
    ) {}

    public function configure(ResolvedConfiguration $resolved, InputInterface $input): void
    {
        $parser = (new RuleOptionsParserFactory())->createFromClasses($this->ruleRegistry->getClasses());
        $cliRuleOptions = (new CliOptionsParser($parser))->parseRuleOptions($input);

        $this->ruleOptionsRegistry->setConfigFileOptions($resolved->ruleOptions);
        foreach ($cliRuleOptions as $ruleName => $options) {
            $this->ruleOptionsRegistry->setCliOptions($ruleName, $options);
        }

        $ruleOptions = array_replace_recursive($resolved->ruleOptions, $cliRuleOptions);
        $this->configurationProvider->setConfiguration($resolved->analysis);
        $this->configurationProvider->setRuleOptions($ruleOptions);
        $this->configureCollectors($ruleOptions);

        if ($resolved->analysis->frameworkNamespaces !== []) {
            $this->frameworkNamespacesHolder->set(
                new FrameworkNamespaces($resolved->analysis->frameworkNamespaces),
            );
        }

        foreach ($this->lifecycleHooks as $hook) {
            $hook->applyResolvedConfiguration($resolved);
        }

        $definitions = $this->computedMetricsResolver->resolve(
            $resolved->computedMetrics,
            $resolved->analysis->excludeHealth,
        );
        ComputedMetricDefinitionHolder::setDefinitions($definitions);

        $capture = $input->hasOption('show-suppressed') && $input->getOption('show-suppressed') === true;
        RuleExclusionCaptureHolder::set($capture);
    }

    /** Clears state that must never leak into logger setup or the next run. */
    public function resetRunState(): void
    {
        $this->ruleOptionsRegistry->resetRuntimeState();
        $this->cacheFactory->reset();
        ComputedMetricDefinitionHolder::reset();
        CollectorConfigHolder::reset();
        $this->frameworkNamespacesHolder->reset();
    }

    /** @param array<string, array<string, mixed>> $ruleOptions */
    private function configureCollectors(array $ruleOptions): void
    {
        $lcomConfig = $ruleOptions['design.lcom'] ?? [];
        $excludeKey = $lcomConfig['exclude_methods'] ?? $lcomConfig['excludeMethods'] ?? null;

        if ($excludeKey === null) {
            return;
        }

        $excludeMethods = match (true) {
            \is_string($excludeKey) && str_contains($excludeKey, ',') => array_map('trim', explode(',', $excludeKey)),
            \is_string($excludeKey) => [$excludeKey],
            \is_array($excludeKey) => array_values($excludeKey),
            default => [],
        };

        if ($excludeMethods !== []) {
            CollectorConfigHolder::set(CollectorConfigHolder::LCOM_EXCLUDE_METHODS, $excludeMethods);
        }
    }
}
