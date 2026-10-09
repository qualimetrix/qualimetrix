<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Finding\ComputedMetricChannelFamily;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleOptionsCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/** Configures the complete ComputedMetrics capability implementation tree. */
final class ComputedMetricsConfigurator implements ContainerConfiguratorInterface
{
    private const string CONFIGURATOR = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Configuration\\ComputedMetricConfiguratorInterface';
    private const string REACH = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\ComputedMetricReachInterface';
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
        $reach = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\ComputedMetricReach';
        $expression = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Evaluation\\ComputedMetricExpression';
        $metricReachCatalog = 'Qualimetrix\\Analysis\\Evidence\\Measurement\\Contract\\MetricReachCatalogInterface';
        $formulaValidator = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\ComputedMetricFormulaValidator';
        $configResolver = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\ComputedMetricsConfigResolver';
        $findingBuilder = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Finding\\ComputedMetricFindingBuilder';
        $evaluator = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Evaluation\\ComputedMetricEvaluator';
        $analysis = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\ComputedMetricAnalysis';
        $healthFormulaExcluder = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Configuration\\HealthFormulaExcluder';
        $delegatingLogger = 'Qualimetrix\\Infrastructure\\Logging\\DelegatingLogger';
        $computedMetricsSection = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Configuration\\ComputedMetricsSection';
        $excludeHealthSection = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Configuration\\ExcludeHealthSection';

        $container->register($computedMetricsSection)->setAutoconfigured(true);
        $container->register($excludeHealthSection)->setAutoconfigured(true);

        $container->register($healthFormulaExcluder);
        $container->setAlias(self::HEALTH_EXCLUSION, $healthFormulaExcluder)->setPublic(true);
        $container->register($expression);
        $container->register($reach)->setArguments([
            new Reference($metricReachCatalog),
            new Reference($expression),
        ]);
        $container->setAlias(self::REACH, $reach);
        $container->register($formulaValidator);
        $container->register($configResolver)->setArguments([
            new Reference($formulaValidator),
            new Reference(self::HEALTH_EXCLUSION),
        ]);
        $container->register($findingBuilder);
        $container->register($analysis)->setArguments([
            new Reference($configResolver),
        ]);
        $container->register($evaluator)->setArguments([
            new Reference($analysis),
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
        $healthDecompositionCatalog = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Metadata\\HealthDecompositionCatalog';
        $healthMetricCatalog = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Metadata\\HealthMetricCatalog';
        $healthSummaryBuilder = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\Summary\\HealthSummaryBuilder';
        $healthScoreDrillDown = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\DrillDown\\HealthScoreDrillDown';
        $worstClassDrillDown = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\DrillDown\\WorstClassDrillDown';
        $worstOffenderBuilder = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Offender\\WorstOffenderBuilder';

        $container->register($metricHintCatalog);
        $container->register($healthDimensionCatalog);
        $container->register($healthDecompositionCatalog);
        $container->register($healthMetricCatalog)->setArguments([
            new Reference($metricHintCatalog),
            new Reference($healthDimensionCatalog),
            new Reference($healthDecompositionCatalog),
        ]);
        $container->setAlias(self::METADATA_PROVIDER, $healthMetricCatalog);
        $container->register($healthSummaryBuilder)->setArguments([
            new Reference($healthMetricCatalog),
            new Reference(self::CATALOG),
        ]);
        $container->register($healthScoreDrillDown)->setArguments([
            new Reference(self::CATALOG),
        ]);
        $container->register($worstOffenderBuilder);
        $container->register($worstClassDrillDown)->setArguments([
            new Reference(self::CATALOG),
            new Reference($worstOffenderBuilder),
        ]);
    }

    /** Registers producer-specific lookups into the ready invocation snapshot. */
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
                    ->setFactory([new Reference(RuleOptionsRegistry::class), 'optionsFor'])
                    ->setArguments([$producerRuleName, $options])
                    ->setShared(false);
            }

            $byProducer[$producerRuleName] = new Reference($id);
        }

        $container->register($producerOptions)
            ->setArguments(['$byProducer' => $byProducer])
            ->setShared(false);

        $container->register($rule, $rule)
            ->setAutoconfigured(true)
            ->setAutowired(false)
            ->setLazy(true);
    }
}
