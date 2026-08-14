<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\TransitionalResolvedConfiguration;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfigurationProviderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CollectorRuntimeConfiguration;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CollectorRuntimeConfigurationStoreInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionCaptureHolder;
use Qualimetrix\Analysis\Finding\Contract\RuleOptionsDocument;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParserFactory;
use Qualimetrix\Core\Coupling\FrameworkNamespaces;
use Qualimetrix\Core\Coupling\FrameworkNamespacesHolder;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Symfony\Component\Console\Input\InputInterface;

/** Configures the analysis engine's per-run rule, collector, and feature state. */
final readonly class AnalysisRuntimeConfigurator
{
    public function __construct(
        private TransitionalRuntimeConfigurationProviderInterface $configurationProvider,
        private RuleConfigurationInterface $ruleOptionsRegistry,
        private RuleRegistryInterface $ruleRegistry,
        private FrameworkNamespacesHolder $frameworkNamespacesHolder,
        private CollectorRuntimeConfigurationStoreInterface $collectorConfigurationStore,
        private CacheFactory $cacheFactory,
    ) {}

    public function configure(TransitionalResolvedConfiguration $resolved, InputInterface $input): void
    {
        $parser = (new RuleOptionsParserFactory())->createFromClasses($this->ruleRegistry->getClasses());
        $cliRuleOptions = (new CliOptionsParser($parser))->parseRuleOptions($input);

        $this->ruleOptionsRegistry->configure(new RuleOptionsDocument($resolved->ruleOptions));
        $this->ruleOptionsRegistry->configureSelection($resolved->ruleSelection);
        foreach ($cliRuleOptions as $ruleName => $options) {
            $this->ruleOptionsRegistry->configureCli($ruleName, $options);
        }

        $ruleOptions = array_replace_recursive($resolved->ruleOptions, $cliRuleOptions);
        $this->configurationProvider->setConfiguration($resolved->runtime);
        $this->configurationProvider->setRuleOptions($ruleOptions);
        $this->configureCollectors($ruleOptions);

        if ($resolved->runtime->frameworkNamespaces !== []) {
            $this->frameworkNamespacesHolder->set(
                new FrameworkNamespaces($resolved->runtime->frameworkNamespaces),
            );
        }

        $capture = $input->hasOption('show-suppressed') && $input->getOption('show-suppressed') === true;
        RuleExclusionCaptureHolder::set($capture);
    }

    /** Clears state that must never leak into logger setup or the next run. */
    public function resetRunState(): void
    {
        $this->cacheFactory->reset();
        $this->ruleOptionsRegistry->resetRuntimeState();
        $this->collectorConfigurationStore->reset();
        $this->frameworkNamespacesHolder->reset();
    }

    /** @param array<string, array<string, mixed>> $ruleOptions */
    private function configureCollectors(array $ruleOptions): void
    {
        $lcomConfig = $ruleOptions['design.lcom'] ?? [];
        $excludeKey = $lcomConfig['exclude_methods'] ?? $lcomConfig['excludeMethods'] ?? null;

        $excludeMethods = match (true) {
            \is_string($excludeKey) && str_contains($excludeKey, ',') => array_map('trim', explode(',', $excludeKey)),
            \is_string($excludeKey) => [$excludeKey],
            \is_array($excludeKey) => array_values($excludeKey),
            default => [],
        };

        $configuration = new CollectorRuntimeConfiguration($excludeMethods);
        $this->collectorConfigurationStore->replace($configuration);
    }
}
