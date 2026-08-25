<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Finding\ComputedMetricChannelFamily;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleOptionsCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/** Configures the complete ComputedMetrics capability implementation tree. */
final class ComputedMetricsConfigurator implements ContainerConfiguratorInterface
{
    private const string CONFIGURATOR = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Configuration\\ComputedMetricConfiguratorInterface';
    private const string CATALOG = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\ComputedMetricDefinitionCatalogInterface';
    private const string HEALTH_EXCLUSION = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Configuration\\HealthFormulaExclusionInterface';
    private const string METADATA_PROVIDER = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\Metadata\\HealthMetricMetadataProviderInterface';

    public function configure(ContainerBuilder $container): void
    {
        $this->registerRoot($container);
        $this->registerHealth($container);
        $this->registerRule($container);
    }

    private function registerRoot(ContainerBuilder $container): void
    {
        $formulaValidator = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\ComputedMetricFormulaValidator';
        $configResolver = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\ComputedMetricsConfigResolver';
        $contributionReader = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Configuration\\ComputedMetricContributionReader';
        $findingBuilder = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Finding\\ComputedMetricFindingBuilder';
        $evaluator = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Evaluation\\ComputedMetricEvaluator';
        $analysis = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\ComputedMetricAnalysis';
        $healthFormulaExcluder = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Configuration\\HealthFormulaExcluder';
        $delegatingLogger = 'Qualimetrix\\Infrastructure\\Logging\\DelegatingLogger';

        $container->register($healthFormulaExcluder);
        $container->setAlias(self::HEALTH_EXCLUSION, $healthFormulaExcluder)->setPublic(true);
        $container->register($formulaValidator);
        $container->register($configResolver)->setArguments([
            new Reference($formulaValidator),
            new Reference(self::HEALTH_EXCLUSION),
        ]);
        $container->register($contributionReader);
        $container->register($findingBuilder);
        $container->register($analysis)->setArguments([
            new Reference($configResolver),
            new Reference($contributionReader),
        ]);
        $container->register($evaluator)->setArguments([
            new Reference(self::CATALOG),
            new Reference(ProfilerInterface::class),
            new Reference($delegatingLogger),
        ]);
        $container->setAlias(self::CONFIGURATOR, $analysis)->setPublic(true);
        $container->setAlias(self::CATALOG, $analysis)->setPublic(true);
    }

    private function registerHealth(ContainerBuilder $container): void
    {
        $metricHintCatalog = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Metadata\\MetricHintCatalog';
        $healthDimensionCatalog = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Metadata\\HealthDimensionCatalog';
        $healthMetricCatalog = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Metadata\\HealthMetricCatalog';
        $healthSummaryBuilder = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\Summary\\HealthSummaryBuilder';
        $healthScoreDrillDown = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\DrillDown\\HealthScoreDrillDown';
        $worstClassDrillDown = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\DrillDown\\WorstClassDrillDown';
        $worstOffenderBuilder = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Offender\\WorstOffenderBuilder';

        $container->register($metricHintCatalog);
        $container->register($healthDimensionCatalog);
        $container->register($healthMetricCatalog)->setArguments([
            new Reference($metricHintCatalog),
            new Reference($healthDimensionCatalog),
        ]);
        $container->setAlias(self::METADATA_PROVIDER, $healthMetricCatalog);
        $container->register($healthSummaryBuilder)->setArguments([
            new Reference($healthMetricCatalog),
            new Reference(self::CATALOG),
        ]);
        $container->register($healthScoreDrillDown)->setArguments([
            new Reference($healthMetricCatalog),
            new Reference(self::CATALOG),
        ]);
        $container->register($worstOffenderBuilder);
        $container->register($worstClassDrillDown)->setArguments([
            new Reference(self::CATALOG),
            new Reference($worstOffenderBuilder),
        ]);
    }

    /**
     * One rule class, seven producers — so seven Options objects, each built by
     * the same factory the options compiler pass would have used.
     *
     * That pass walks tagged rule **services** and therefore cannot see a
     * producer without one. Registering the six here is not only about the
     * `enabled` switch: `RuleOptionsFactory::create()` is where a producer's
     * `exclude_namespaces` / `exclude_namespace_channels` / `exclude_paths`
     * keys are lifted into the exclusion providers, so a producer whose options
     * are never built passes validation and then excludes nothing.
     */
    private function registerRule(ContainerBuilder $container): void
    {
        $rule = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\ComputedMetricRule';
        $options = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\ComputedMetricRuleOptions';
        $producerOptions = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\ComputedMetricProducerOptions';

        $byProducer = [];

        foreach (ComputedMetricChannelFamily::PRODUCER_RULE_NAMES as $producerRuleName) {
            $id = RuleOptionsCompilerPass::optionsServiceId($producerRuleName, $options);

            if (!$container->hasDefinition($id)) {
                $container->register($id, $options)
                    ->setFactory([new Reference(RuleOptionsFactory::class), 'create'])
                    ->setArguments([$producerRuleName, $options]);
            }

            $byProducer[$producerRuleName] = new Reference($id);
        }

        $container->register($producerOptions)->setArguments(['$byProducer' => $byProducer]);

        $container->register($rule, $rule)
            ->setAutoconfigured(true)
            ->setAutowired(false)
            ->setLazy(true);
    }
}
