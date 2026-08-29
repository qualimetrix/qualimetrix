<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\BooleanArgumentRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\ConstructorOverinjectionRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\CountInLoopRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\DebugCodeRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\EmptyCatchRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\ErrorSuppressionRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\EvalRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\ExitRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\GotoRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\IdenticalSubExpressionRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\LongParameterListRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\SuperglobalsRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\UnreachableCodeRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\UnusedPrivateRule;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfigurableInterface;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfigurationResolverInterface;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfigurationStoreInterface;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomCollector;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomRule;
use Qualimetrix\Analysis\Evidence\Cohesion\TccLccCollector;
use Qualimetrix\Analysis\Evidence\Complexity\CognitiveComplexityCollector;
use Qualimetrix\Analysis\Evidence\Complexity\CognitiveComplexityRule;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityRule;
use Qualimetrix\Analysis\Evidence\Complexity\CyclomaticComplexityCollector;
use Qualimetrix\Analysis\Evidence\Complexity\NpathComplexityCollector;
use Qualimetrix\Analysis\Evidence\Complexity\NpathComplexityRule;
use Qualimetrix\Analysis\Evidence\Complexity\WmcRule;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricAnalysis;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricsConfigResolver;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\HealthFormulaExclusionInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration\HealthFormulaExcluder;
use Qualimetrix\Analysis\Evidence\Coupling\CboRule;
use Qualimetrix\Analysis\Evidence\Coupling\ClassRankRule;
use Qualimetrix\Analysis\Evidence\Coupling\Contract\Configuration\CouplingConfiguratorInterface;
use Qualimetrix\Analysis\Evidence\Coupling\CouplingAnalysis;
use Qualimetrix\Analysis\Evidence\Coupling\DistanceRule;
use Qualimetrix\Analysis\Evidence\Coupling\InstabilityRule;
use Qualimetrix\Analysis\Evidence\Coupling\RfcCollector;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyTraversalParticipantInterface;
use Qualimetrix\Analysis\Evidence\Design\DataClassRule;
use Qualimetrix\Analysis\Evidence\Design\GodClassRule;
use Qualimetrix\Analysis\Evidence\Design\InheritanceDepthCollector;
use Qualimetrix\Analysis\Evidence\Design\InheritanceRule;
use Qualimetrix\Analysis\Evidence\Design\NocRule;
use Qualimetrix\Analysis\Evidence\Design\ParamTypeCoverageRule;
use Qualimetrix\Analysis\Evidence\Design\PropertyTypeCoverageRule;
use Qualimetrix\Analysis\Evidence\Design\ReturnTypeCoverageRule;
use Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationOptions;
use Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationRule;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicationDetector;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicationResultProvider;
use Qualimetrix\Analysis\Evidence\Maintainability\HalsteadCollector;
use Qualimetrix\Analysis\Evidence\Maintainability\MaintainabilityIndexCollector;
use Qualimetrix\Analysis\Evidence\Maintainability\MaintainabilityRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\FileMeasurementCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ProjectNamespaceResolverInterface;
use Qualimetrix\Analysis\Evidence\Security\CommandInjectionRule;
use Qualimetrix\Analysis\Evidence\Security\HardcodedCredentialsRule;
use Qualimetrix\Analysis\Evidence\Security\SensitiveParameterRule;
use Qualimetrix\Analysis\Evidence\Security\SqlInjectionRule;
use Qualimetrix\Analysis\Evidence\Security\XssRule;
use Qualimetrix\Analysis\Evidence\Size\ClassCountCollector;
use Qualimetrix\Analysis\Evidence\Size\ClassCountRule;
use Qualimetrix\Analysis\Evidence\Size\LocCollector;
use Qualimetrix\Analysis\Evidence\Size\MethodCountCollector;
use Qualimetrix\Analysis\Evidence\Size\MethodCountRule;
use Qualimetrix\Analysis\Evidence\Size\PropertyCountRule;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfigurationResolverInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePolicyConfiguratorInterface;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\UnassignedClassRule;
use Qualimetrix\Analysis\Policy\Inline\Contract\AnnotationSuppressionInterface;
use Qualimetrix\Analysis\Policy\Inline\Directive\UnusedDirectiveRule;
use Qualimetrix\Analysis\Run\Collection\CollectionOrchestrator;
use Qualimetrix\Analysis\Run\Collection\FileProcessor;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfigurationResolverInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Pipeline\AnalysisPipeline;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Cache\CacheInterface;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationResolverInterface;
use Qualimetrix\Infrastructure\Console\AnalysisRuntimeConfigurator;
use Qualimetrix\Infrastructure\Console\CheckScopeResolver;
use Qualimetrix\Infrastructure\Console\Command\BaselineRun;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\Command\GraphExportCommand;
use Qualimetrix\Infrastructure\Console\Command\RulesCommand;
use Qualimetrix\Infrastructure\Console\MeasuredFindingSet;
use Qualimetrix\Infrastructure\Console\RuleInputValidator;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Logging\DelegatingLogger;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationResolverInterface;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationStoreInterface;
use Qualimetrix\Infrastructure\Parallel\FileProcessingTaskFactory;
use Qualimetrix\Infrastructure\Parallel\Strategy\AmphpParallelStrategy;
use Qualimetrix\Infrastructure\Parallel\Strategy\StrategySelector;
use Qualimetrix\Infrastructure\Rule\Contract\RuleChannelSnapshotFactoryInterface;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Qualimetrix\Reporting\Contract\OutputFormatResolverInterface;
use Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusionsResolverInterface;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeQueryInterface;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use Qualimetrix\Reporting\GraphProjection\Contract\DependencyGraphProjectionInterface;
use ReflectionClass;
use ReflectionProperty;
use SplFileInfo;

#[CoversClass(ContainerFactory::class)]
final class ContainerFactoryTest extends TestCase
{
    private ContainerFactory $factory;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->factory = new ContainerFactory();
        $this->tempDir = sys_get_temp_dir() . '/qmx_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function itCreatesCompiledContainer(): void
    {
        $container = $this->factory->create();

        self::assertTrue($container->isCompiled());

        $configurator = $container->get(ComputedMetricConfiguratorInterface::class);
        $catalog = $container->get(ComputedMetricDefinitionCatalogInterface::class);
        self::assertInstanceOf(ComputedMetricAnalysis::class, $configurator);
        self::assertSame($configurator, $catalog);

        $excluder = $container->get(HealthFormulaExclusionInterface::class);
        self::assertInstanceOf(HealthFormulaExcluder::class, $excluder);

        $couplingConfigurator = $container->get(CouplingConfiguratorInterface::class);
        self::assertInstanceOf(CouplingAnalysis::class, $couplingConfigurator);

        $resolverProperty = new ReflectionProperty(ComputedMetricAnalysis::class, 'configResolver');
        $resolver = $resolverProperty->getValue($configurator);
        self::assertInstanceOf(ComputedMetricsConfigResolver::class, $resolver);
        $excluderProperty = new ReflectionProperty(ComputedMetricsConfigResolver::class, 'healthFormulaExcluder');
        self::assertSame($excluder, $excluderProperty->getValue($resolver));

        $pipeline = $container->get(AnalysisPipelineInterface::class);
        $ruleConfiguration = $container->get(RuleConfigurationInterface::class);
        $producerPreparation = (new ReflectionProperty(AnalysisPipeline::class, 'ruleProducerPreparation'))->getValue($pipeline);
        self::assertSame(
            $ruleConfiguration,
            (new ReflectionProperty($producerPreparation, 'ruleConfiguration'))->getValue($producerPreparation),
        );
        self::assertFalse((new ReflectionClass(AnalysisPipeline::class))->hasProperty('ruleConfiguration'));
        $collectionOrchestrator = (new ReflectionProperty(AnalysisPipeline::class, 'collectionOrchestrator'))->getValue($pipeline);
        self::assertInstanceOf(CollectionOrchestrator::class, $collectionOrchestrator);
        $fileProcessor = (new ReflectionProperty(CollectionOrchestrator::class, 'fileProcessor'))->getValue($collectionOrchestrator);
        self::assertInstanceOf(FileProcessor::class, $fileProcessor);
        $sourceControlExtractor = (new ReflectionProperty(FileProcessor::class, 'sourceControlExtractor'))->getValue($fileProcessor);
        self::assertSame(
            'Qualimetrix\\Analysis\\Policy\\Inline\\Extraction\\SourceControlExtractor',
            $sourceControlExtractor::class,
        );
        $evaluator = (new ReflectionProperty(AnalysisPipeline::class, 'computedMetricEvaluation'))->getValue($pipeline);
        self::assertInstanceOf(ComputedMetricEvaluator::class, $evaluator);
        $evaluatorLogger = (new ReflectionProperty(ComputedMetricEvaluator::class, 'logger'))->getValue($evaluator);
        $pipelineLogger = (new ReflectionProperty(AnalysisPipeline::class, 'logger'))->getValue($pipeline);
        self::assertInstanceOf(DelegatingLogger::class, $evaluatorLogger);
        self::assertSame($pipelineLogger, $evaluatorLogger);
        $ruleOptionsFactory = $container->get(RuleOptionsFactory::class);
        self::assertInstanceOf(RuleOptionsFactory::class, $ruleOptionsFactory);
        self::assertSame(
            $evaluatorLogger,
            (new ReflectionProperty(RuleOptionsFactory::class, 'logger'))->getValue($ruleOptionsFactory),
        );

        $annotationSuppression = $container->get(AnnotationSuppressionInterface::class);
        $gitScopeQuery = $container->get(GitScopeQueryInterface::class);
        self::assertSame(
            'Qualimetrix\\Analysis\\Policy\\Inline\\Suppression\\SuppressionFilter',
            $annotationSuppression::class,
        );
        self::assertSame(
            'Qualimetrix\\Infrastructure\\Git\\ReportingGitScopeQuery',
            $gitScopeQuery::class,
        );

        $checkCommand = $container->get(CheckCommand::class);
        $orchestrator = (new ReflectionProperty(CheckCommand::class, 'findingFilterOrchestrator'))->getValue($checkCommand);
        $projector = (new ReflectionProperty($orchestrator, 'findingProjector'))->getValue($orchestrator);
        self::assertSame(
            'Qualimetrix\\Reporting\\FindingProjection\\FindingProjector',
            $projector::class,
        );
        self::assertSame(
            $annotationSuppression,
            (new ReflectionProperty($projector, 'annotationSuppression'))->getValue($projector),
        );
        self::assertSame(
            $gitScopeQuery,
            (new ReflectionProperty($projector, 'gitScopeQuery'))->getValue($projector),
        );
    }

    #[Test]
    public function itWiresDependencyModelAndGraphProjectionThroughPublicContracts(): void
    {
        $container = $this->factory->create();

        $graphBuilder = $container->get(DependencyGraphBuilderInterface::class);
        self::assertInstanceOf(DependencyGraphBuilderInterface::class, $graphBuilder);
        self::assertSame(
            'Qualimetrix\\Analysis\\Evidence\\DependencyModel\\DependencyGraphBuilder',
            $graphBuilder::class,
        );

        $pipeline = $container->get(AnalysisPipelineInterface::class);
        $pipelineBuilder = (new ReflectionProperty(AnalysisPipeline::class, 'graphBuilder'))->getValue($pipeline);
        self::assertSame($graphBuilder, $pipelineBuilder);

        $projection = $container->get(DependencyGraphProjectionInterface::class);
        self::assertInstanceOf(DependencyGraphProjectionInterface::class, $projection);
        self::assertSame(
            'Qualimetrix\\Reporting\\GraphProjection\\DependencyGraphProjector',
            $projection::class,
        );

        $command = $container->get(GraphExportCommand::class);
        $commandProjection = (new ReflectionProperty(GraphExportCommand::class, 'projection'))->getValue($command);
        self::assertSame($projection, $commandProjection);
    }

    #[Test]
    public function itHasAnalysisPipeline(): void
    {
        $container = $this->factory->create();

        self::assertTrue($container->has(AnalysisPipelineInterface::class));
        self::assertInstanceOf(AnalysisPipelineInterface::class, $container->get(AnalysisPipelineInterface::class));
    }

    #[Test]
    public function itHasFormatterRegistry(): void
    {
        $container = $this->factory->create();

        self::assertTrue($container->has(FormatterRegistryInterface::class));
        self::assertInstanceOf(
            FormatterRegistryInterface::class,
            $container->get(FormatterRegistryInterface::class),
        );
    }

    #[Test]
    public function itHasCache(): void
    {
        $container = $this->factory->create();

        self::assertTrue($container->has(CacheInterface::class));
        self::assertInstanceOf(CacheInterface::class, $container->get(CacheInterface::class));
    }

    #[Test]
    public function itHasRuleRegistry(): void
    {
        $container = $this->factory->create();

        self::assertTrue($container->has(RuleRegistryInterface::class));
        $registry = $container->get(RuleRegistryInterface::class);
        self::assertInstanceOf(RuleRegistryInterface::class, $registry);

        // Registry should contain rule classes
        $classes = $registry->getClasses();
        self::assertNotEmpty($classes);
    }

    #[Test]
    public function itWiresTheDuplicationCapabilityThroughItsContractAndRegistries(): void
    {
        $container = $this->factory->create();

        $pipeline = $container->get(AnalysisPipelineInterface::class);
        $producerPreparation = (new ReflectionProperty(AnalysisPipeline::class, 'ruleProducerPreparation'))->getValue($pipeline);
        $fileSetInspection = (new ReflectionProperty($producerPreparation, 'fileSetInspection'))->getValue($producerPreparation);
        $participants = (new ReflectionProperty($fileSetInspection, 'participants'))->getValue($fileSetInspection);
        self::assertCount(1, $participants);
        $inspection = $participants[0];
        self::assertInstanceOf(DuplicationDetector::class, $inspection);

        $providerProperty = new ReflectionProperty(DuplicationDetector::class, 'resultProvider');
        self::assertInstanceOf(DuplicationResultProvider::class, $providerProperty->getValue($inspection));

        $rulesCommand = $container->get(RulesCommand::class);
        self::assertInstanceOf(RulesCommand::class, $rulesCommand);
        $ruleExecution = $container->get(RuleExecutionInterface::class);
        self::assertInstanceOf(RuleExecutionInterface::class, $ruleExecution);
        self::assertSame($ruleExecution, $container->get(RuleExecutionInterface::class));
        $duplicationRules = array_filter(
            $ruleExecution->allRules(),
            static fn($rule): bool => $rule->name === CodeDuplicationRule::NAME,
        );
        self::assertCount(1, $duplicationRules);
        $duplicationRule = array_values($duplicationRules)[0];
        self::assertSame(CodeDuplicationOptions::class, $duplicationRule->optionsClass);

        $channels = $container->get(ChannelDeclarationRegistryInterface::class);
        self::assertInstanceOf(ChannelDeclarationRegistryInterface::class, $channels);
        self::assertArrayHasKey(
            CodeDuplicationRule::NAME,
            $channels->staticDeclarations(),
        );

        $registry = $container->get(RuleRegistryInterface::class);
        self::assertInstanceOf(RuleRegistryInterface::class, $registry);
        self::assertSame(1, \count(array_filter(
            $registry->getClasses(),
            static fn(string $ruleClass): bool => $ruleClass === CodeDuplicationRule::class,
        )));

        $ruleConfiguration = $container->get(RuleConfigurationInterface::class);
        self::assertInstanceOf(RuleConfigurationInterface::class, $ruleConfiguration);
        $ruleConfiguration->configureSelection(new RuleSelection(only: [CodeDuplicationRule::NAME]));
        $ruleConfiguration->configureCli(CodeDuplicationRule::NAME, [
            'min_lines' => 2,
            'min_tokens' => 10,
        ]);

        self::assertInstanceOf(AnalysisPipeline::class, $pipeline);
        $source = <<<'PHP'
<?php

function duplicatedFixture(): int
{
    $first = 1;
    $second = 2;
    $third = $first + $second;
    $fourth = $third * 2;
    $fifth = $fourth + $third;

    return $fifth;
}
PHP;
        $firstPath = $this->tempDir . '/First.php';
        $secondPath = $this->tempDir . '/Second.php';
        self::assertNotFalse(file_put_contents($firstPath, $source));
        self::assertNotFalse(file_put_contents($secondPath, $source));

        $duplicateFiles = [new SplFileInfo($firstPath), new SplFileInfo($secondPath)];

        $selection = $ruleConfiguration->selection();
        $fileSetInspection->inspect(
            $duplicateFiles,
            AbsolutePath::fromString($this->tempDir),
            $selection->only,
            $selection->disabled,
        );
        $resultProvider = $providerProperty->getValue($inspection);
        self::assertNotEmpty($resultProvider->all());

        $ruleConfiguration->configureSelection(new RuleSelection(disabled: [CodeDuplicationRule::NAME]));
        $selection = $ruleConfiguration->selection();
        $fileSetInspection->inspect(
            $duplicateFiles,
            AbsolutePath::fromString($this->tempDir),
            $selection->only,
            $selection->disabled,
        );

        self::assertSame([], $resultProvider->all());

        $ruleConfiguration->configureSelection(new RuleSelection(only: [CodeDuplicationRule::NAME]));
        $selection = $ruleConfiguration->selection();
        $fileSetInspection->inspect(
            $duplicateFiles,
            AbsolutePath::fromString($this->tempDir),
            $selection->only,
            $selection->disabled,
        );

        self::assertNotEmpty($resultProvider->all());

        $fileSetInspection->inspect(
            [new SplFileInfo($firstPath)],
            AbsolutePath::fromString($this->tempDir),
            $selection->only,
            $selection->disabled,
        );

        self::assertSame([], $resultProvider->all());
    }

    #[Test]
    public function itWiresFileSetInspectionAndTraversalContractsWithoutLegacyCapabilityImports(): void
    {
        $container = $this->factory->create();

        $participant = $container->get(DependencyTraversalParticipantInterface::class);
        self::assertInstanceOf(DependencyTraversalParticipantInterface::class, $participant);

        $pipeline = $container->get(AnalysisPipelineInterface::class);
        $orchestrator = (new ReflectionProperty(AnalysisPipeline::class, 'collectionOrchestrator'))->getValue($pipeline);
        $selector = (new ReflectionProperty($orchestrator, 'strategySelector'))->getValue($orchestrator);
        self::assertInstanceOf(StrategySelector::class, $selector);
        $factory = (new ReflectionProperty(AmphpParallelStrategy::class, 'fileProcessingTaskFactory'))
            ->getValue((new ReflectionProperty(StrategySelector::class, 'amphpStrategy'))->getValue($selector));
        self::assertInstanceOf(FileProcessingTaskFactory::class, $factory);
        self::assertSame(
            'Qualimetrix\\Analysis\\Evidence\\DependencyModel\\Extraction\\DependencyVisitor',
            (new ReflectionProperty(FileProcessingTaskFactory::class, 'dependencyTraversalParticipantClass'))->getValue($factory),
        );

        $store = (new ReflectionProperty(FileProcessingTaskFactory::class, 'lcomConfigurationStore'))->getValue($factory);
        self::assertInstanceOf(LcomCollectionConfigurationStoreInterface::class, $store);
        self::assertSame(
            $store,
            (new ReflectionProperty(FileProcessingTaskFactory::class, 'lcomConfigurationStore'))
                ->getValue($factory),
        );
        self::assertSame([], $store->current()->excludedMethods);
        $analysisRuntimeConstructor = (new ReflectionClass(AnalysisRuntimeConfigurator::class))->getConstructor();
        $runtimeConstructor = (new ReflectionClass(RuntimeConfigurator::class))->getConstructor();
        self::assertNotNull($analysisRuntimeConstructor);
        self::assertNotNull($runtimeConstructor);
        self::assertCount(7, $analysisRuntimeConstructor->getParameters());
        self::assertCount(7, $runtimeConstructor->getParameters());
        $checkConstructor = (new ReflectionClass(CheckCommand::class))->getConstructor();
        $baselineRunConstructor = (new ReflectionClass(BaselineRun::class))->getConstructor();
        $measuredFindingSetConstructor = (new ReflectionClass(MeasuredFindingSet::class))->getConstructor();
        self::assertNotNull($checkConstructor);
        self::assertNotNull($baselineRunConstructor);
        self::assertNotNull($measuredFindingSetConstructor);
        self::assertCount(12, $checkConstructor->getParameters());
        self::assertCount(8, $baselineRunConstructor->getParameters());
        self::assertCount(3, $measuredFindingSetConstructor->getParameters());
        $pipelineConstructor = (new ReflectionClass(AnalysisPipeline::class))->getConstructor();
        self::assertNotNull($pipelineConstructor);
        self::assertCount(10, $pipelineConstructor->getParameters());

        $runtimeConfigurator = $container->get(RuntimeConfigurator::class);
        self::assertInstanceOf(RuntimeConfigurator::class, $runtimeConfigurator);
        $analysisRuntime = (new ReflectionProperty(RuntimeConfigurator::class, 'analysisRuntimeConfigurator'))->getValue($runtimeConfigurator);
        self::assertInstanceOf(AnalysisRuntimeConfigurator::class, $analysisRuntime);
        self::assertInstanceOf(
            RuleConfigurationInterface::class,
            (new ReflectionProperty(AnalysisRuntimeConfigurator::class, 'ruleOptionsRegistry'))->getValue($analysisRuntime),
        );
        self::assertInstanceOf(
            LcomCollectionConfigurationResolverInterface::class,
            (new ReflectionProperty(AnalysisRuntimeConfigurator::class, 'lcomConfigurationResolver'))->getValue($analysisRuntime),
        );
        self::assertInstanceOf(
            LcomCollectionConfigurationStoreInterface::class,
            (new ReflectionProperty(AnalysisRuntimeConfigurator::class, 'lcomConfigurationStore'))->getValue($analysisRuntime),
        );
        self::assertInstanceOf(
            ArchitecturePolicyConfiguratorInterface::class,
            (new ReflectionProperty(AnalysisRuntimeConfigurator::class, 'architecturePolicyConfigurator'))->getValue($analysisRuntime),
        );
        self::assertInstanceOf(
            ComputedMetricConfiguratorInterface::class,
            (new ReflectionProperty(AnalysisRuntimeConfigurator::class, 'computedMetricConfigurator'))->getValue($analysisRuntime),
        );
        self::assertInstanceOf(
            CouplingConfiguratorInterface::class,
            (new ReflectionProperty(AnalysisRuntimeConfigurator::class, 'couplingConfigurator'))->getValue($analysisRuntime),
        );
        $analysisRuleInputValidator = (new ReflectionProperty(AnalysisRuntimeConfigurator::class, 'ruleInputValidator'))
            ->getValue($analysisRuntime);
        self::assertInstanceOf(RuleInputValidator::class, $analysisRuleInputValidator);
        $checkCommand = $container->get(CheckCommand::class);
        self::assertSame(
            $analysisRuleInputValidator,
            (new ReflectionProperty(CheckCommand::class, 'ruleInputValidator'))->getValue($checkCommand),
        );
        self::assertFalse((new ReflectionClass(AnalysisRuntimeConfigurator::class))->hasProperty('ruleRegistry'));
        self::assertFalse((new ReflectionClass(AnalysisRuntimeConfigurator::class))->hasProperty('findingConfigurationResolver'));

        $runtimeCollectors = (new ReflectionProperty($store, 'collectors'))->getValue($store);
        self::assertIsIterable($runtimeCollectors);
        $runtimeCollectors = [...$runtimeCollectors];
        self::assertNotEmpty($runtimeCollectors);
        foreach ($runtimeCollectors as $runtimeCollector) {
            self::assertInstanceOf(LcomCollectionConfigurableInterface::class, $runtimeCollector);
        }

        $producerPreparation = (new ReflectionProperty(AnalysisPipeline::class, 'ruleProducerPreparation'))->getValue($pipeline);
        $fileSetComposite = (new ReflectionProperty($producerPreparation, 'fileSetInspection'))->getValue($producerPreparation);
        $participants = (new ReflectionProperty($fileSetComposite, 'participants'))->getValue($fileSetComposite);
        self::assertCount(1, $participants);
        self::assertInstanceOf(DuplicationDetector::class, $participants[0]);
    }

    #[Test]
    public function itHasCheckCommand(): void
    {
        $container = $this->factory->create();

        self::assertTrue($container->has(CheckCommand::class));
        $command = $container->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $command);
        self::assertInstanceOf(
            CheckScopeResolver::class,
            (new ReflectionProperty(CheckCommand::class, 'checkScopeResolver'))->getValue($command),
        );
        self::assertInstanceOf(
            RunConfigurationResolverInterface::class,
            (new ReflectionProperty(CheckCommand::class, 'runConfigurationResolver'))->getValue($command),
        );
        self::assertInstanceOf(
            CacheConfigurationResolverInterface::class,
            (new ReflectionProperty(CheckCommand::class, 'cacheConfigurationResolver'))->getValue($command),
        );
        self::assertInstanceOf(
            ParallelConfigurationResolverInterface::class,
            (new ReflectionProperty(CheckCommand::class, 'parallelConfigurationResolver'))->getValue($command),
        );
        self::assertInstanceOf(
            ConfiguredFindingExclusionsResolverInterface::class,
            (new ReflectionProperty(CheckCommand::class, 'findingExclusionsResolver'))->getValue($command),
        );
        self::assertInstanceOf(
            OutputFormatResolverInterface::class,
            (new ReflectionProperty(CheckCommand::class, 'outputFormatResolver'))->getValue($command),
        );
        $ruleInputValidator = (new ReflectionProperty(CheckCommand::class, 'ruleInputValidator'))->getValue($command);
        self::assertInstanceOf(RuleInputValidator::class, $ruleInputValidator);
        self::assertInstanceOf(
            FindingConfigurationResolverInterface::class,
            (new ReflectionProperty(RuleInputValidator::class, 'findingConfigurationResolver'))->getValue($ruleInputValidator),
        );
        self::assertInstanceOf(
            RuleChannelSnapshotFactoryInterface::class,
            (new ReflectionProperty(RuleInputValidator::class, 'ruleChannelSnapshotFactory'))->getValue($ruleInputValidator),
        );
        self::assertFalse((new ReflectionClass(CheckCommand::class))->hasProperty('logger'));
        self::assertFalse((new ReflectionClass(CheckCommand::class))->hasProperty('gitScopeResolver'));
        self::assertFalse((new ReflectionClass(CheckCommand::class))->hasProperty('scopeWarningChecker'));
    }

    #[Test]
    public function itHasBaselineCleanupCommandWithAllDependencies(): void
    {
        $container = $this->factory->create();

        self::assertTrue($container->has(\Qualimetrix\Infrastructure\Console\Command\BaselineCleanupCommand::class));
        $command = $container->get(\Qualimetrix\Infrastructure\Console\Command\BaselineCleanupCommand::class);
        self::assertInstanceOf(\Qualimetrix\Infrastructure\Console\Command\BaselineCleanupCommand::class, $command);
    }

    #[Test]
    public function itRegistersAllCollectorsViaCompilerPass(): void
    {
        $container = $this->factory->create();

        // CompositeCollector is private/inlined, but we can verify via AnalysisPipeline
        $pipeline = $container->get(AnalysisPipelineInterface::class);
        self::assertInstanceOf(AnalysisPipelineInterface::class, $pipeline);
    }

    #[Test]
    public function itInjectsRulesIntoRuleExecution(): void
    {
        $container = $this->factory->create();
        $pipeline = $container->get(AnalysisPipelineInterface::class);

        // If container compiles successfully and AnalysisPipeline is available,
        // rules were injected by RuleCompilerPass
        self::assertInstanceOf(AnalysisPipelineInterface::class, $pipeline);
    }

    #[Test]
    public function itRegistersFormattersViaCompilerPass(): void
    {
        $container = $this->factory->create();
        $registry = $container->get(FormatterRegistryInterface::class);

        self::assertInstanceOf(FormatterRegistryInterface::class, $registry);
        self::assertTrue($registry->has('text'));
    }

    #[Test]
    public function itAllowsConfiguringRuleOptionsAtRuntime(): void
    {
        $container = $this->factory->create();

        $ruleOptionsRegistry = $container->get(RuleConfigurationInterface::class);
        self::assertInstanceOf(RuleConfigurationInterface::class, $ruleOptionsRegistry);
        $ruleExecution = $container->get(RuleExecutionInterface::class);
        self::assertInstanceOf(RuleExecutionInterface::class, $ruleExecution);
        self::assertSame(
            $ruleOptionsRegistry,
            (new ReflectionProperty($ruleExecution, 'ruleOptionsRegistry'))->getValue($ruleExecution),
        );

        $ruleOptionsRegistry->configureCli('cyclomatic-complexity', [
            'warningThreshold' => 20,
            'errorThreshold' => 40,
        ]);

        // Container should still work after configuration
        self::assertTrue($container->isCompiled());
    }

    #[Test]
    public function itWiresOwnerLocalRuntimeConfiguration(): void
    {
        $container = $this->factory->create();

        $runtimeConfigurator = $container->get(RuntimeConfigurator::class);
        self::assertInstanceOf(
            CacheFactory::class,
            (new ReflectionProperty(RuntimeConfigurator::class, 'cacheFactory'))->getValue($runtimeConfigurator),
        );
        self::assertInstanceOf(
            ParallelConfigurationStoreInterface::class,
            (new ReflectionProperty(RuntimeConfigurator::class, 'parallelConfigurationStore'))->getValue($runtimeConfigurator),
        );
    }

    #[Test]
    public function itCreatesContainerWithDefaultConfiguration(): void
    {
        // ContainerFactory is created without arguments
        $container = $this->factory->create();

        self::assertTrue($container->isCompiled());
        self::assertTrue($container->has(AnalysisPipelineInterface::class));
    }

    /**
     * Verifies that all expected formatters are registered in FormatterRegistry.
     * This test protects against accidental exclusion of formatters due to
     * changes in registerClasses() patterns.
     */
    #[Test]
    public function itRegistersAllFormatters(): void
    {
        $container = $this->factory->create();
        $registry = $container->get(FormatterRegistryInterface::class);
        self::assertInstanceOf(FormatterRegistryInterface::class, $registry);

        $expectedFormatters = [
            'summary',
            'text',
            'json',
            'checkstyle',
            'sarif',
            'gitlab',
            'github',
            'metrics',
            'health',
            'html',
            'suppressed',
        ];

        foreach ($expectedFormatters as $name) {
            self::assertTrue(
                $registry->has($name),
                \sprintf("Formatter '%s' should be registered in FormatterRegistry", $name),
            );
        }

        // text-verbose is registered but hidden from getAvailableNames() (deprecated)
        self::assertTrue($registry->has('text-verbose'), 'Deprecated text-verbose formatter should still be registered');

        // Verify we have exactly the expected number of public formatters
        self::assertCount(
            \count($expectedFormatters),
            $registry->getAvailableNames(),
            'FormatterRegistry should contain exactly ' . \count($expectedFormatters) . ' public formatters',
        );
    }

    /**
     * Verifies that all expected metric collectors are registered in CompositeCollector.
     * This test protects against accidental exclusion of collectors due to
     * changes in registerClasses() patterns.
     */
    #[Test]
    public function itRegistersAllMetricCollectors(): void
    {
        $container = $this->factory->create();

        $compositeCollector = $container->get('qmx.measurement.file_collector');
        self::assertInstanceOf(FileMeasurementCollectorInterface::class, $compositeCollector);

        $collectors = $compositeCollector->getCollectors();
        $collectorClasses = array_map(static fn($c) => $c::class, $collectors);

        // Expected base collectors (MetricCollectorInterface)
        $expectedCollectors = [
            CyclomaticComplexityCollector::class,
            CognitiveComplexityCollector::class,
            NpathComplexityCollector::class,
            LocCollector::class,
            ClassCountCollector::class,
            HalsteadCollector::class,
            MethodCountCollector::class,
            LcomCollector::class,
            TccLccCollector::class,
            InheritanceDepthCollector::class,
            RfcCollector::class,
        ];

        foreach ($expectedCollectors as $expectedClass) {
            self::assertContains(
                $expectedClass,
                $collectorClasses,
                \sprintf("Collector '%s' should be registered in CompositeCollector", $expectedClass),
            );
        }
    }

    /**
     * Verifies that derived collectors (DerivedCollectorInterface) are registered.
     */
    #[Test]
    public function itRegistersDerivedCollectors(): void
    {
        $container = $this->factory->create();

        $compositeCollector = $container->get('qmx.measurement.file_collector');
        self::assertInstanceOf(FileMeasurementCollectorInterface::class, $compositeCollector);

        $derivedCollectors = $compositeCollector->getDerivedCollectors();
        $derivedClasses = array_map(static fn($c) => $c::class, $derivedCollectors);

        // MaintainabilityIndexCollector depends on Halstead and CCN metrics
        self::assertContains(
            MaintainabilityIndexCollector::class,
            $derivedClasses,
            'MaintainabilityIndexCollector should be registered as derived collector',
        );
    }

    /**
     * Verifies that global context collectors are properly wired.
     * These collectors are private services that get inlined by Symfony DI.
     * We verify they work by checking that the pipeline can be instantiated.
     */
    #[Test]
    public function itWiresGlobalContextCollectors(): void
    {
        $container = $this->factory->create();

        // If AnalysisPipeline can be created, global collectors were wired correctly
        $pipeline = $container->get(AnalysisPipelineInterface::class);
        self::assertInstanceOf(AnalysisPipelineInterface::class, $pipeline);
    }

    /**
     * Verifies that all expected rules are registered in RuleRegistry.
     * This test protects against accidental omission of rules in ContainerFactory.
     */
    #[Test]
    public function itRegistersAllRules(): void
    {
        $container = $this->factory->create();
        $registry = $container->get(RuleRegistryInterface::class);
        self::assertInstanceOf(RuleRegistryInterface::class, $registry);

        $expectedRuleClasses = [
            ComplexityRule::class,
            CognitiveComplexityRule::class,
            NpathComplexityRule::class,
            MethodCountRule::class,
            ClassCountRule::class,
            PropertyCountRule::class,
            MaintainabilityRule::class,
            LcomRule::class,
            InheritanceRule::class,
            WmcRule::class,
            NocRule::class,
            InstabilityRule::class,
            CboRule::class,
            DistanceRule::class,
            CircularDependencyRule::class,
            LayerViolationRule::class,
            UnusedDirectiveRule::class,
            LongParameterListRule::class,
            BooleanArgumentRule::class,
            CountInLoopRule::class,
            DebugCodeRule::class,
            EmptyCatchRule::class,
            ErrorSuppressionRule::class,
            EvalRule::class,
            ExitRule::class,
            GotoRule::class,
            SuperglobalsRule::class,
            UnreachableCodeRule::class,
            ParamTypeCoverageRule::class,
            ReturnTypeCoverageRule::class,
            PropertyTypeCoverageRule::class,
            UnassignedClassRule::class,
            HardcodedCredentialsRule::class,
            ClassRankRule::class,
            SqlInjectionRule::class,
            XssRule::class,
            CommandInjectionRule::class,
            SensitiveParameterRule::class,
            UnusedPrivateRule::class,
            IdenticalSubExpressionRule::class,
            CodeDuplicationRule::class,
            \Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricRule::class,
            ConstructorOverinjectionRule::class,
            DataClassRule::class,
            GodClassRule::class,
        ];

        $registeredClasses = $registry->getClasses();

        foreach ($expectedRuleClasses as $expectedClass) {
            self::assertContains(
                $expectedClass,
                $registeredClasses,
                \sprintf("Rule '%s' should be registered in RuleRegistry", $expectedClass),
            );
        }

        // Verify we have exactly the expected number of rules
        self::assertCount(
            \count($expectedRuleClasses),
            $registeredClasses,
            'RuleRegistry should contain exactly ' . \count($expectedRuleClasses) . ' rules',
        );
    }

    #[Test]
    public function itInjectsProjectNamespaceResolverIntoDistanceRule(): void
    {
        $container = $this->factory->create();

        // Verify ProjectNamespaceResolverInterface is registered
        self::assertTrue($container->has(ProjectNamespaceResolverInterface::class));
        self::assertInstanceOf(
            ProjectNamespaceResolverInterface::class,
            $container->get(ProjectNamespaceResolverInterface::class),
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = scandir($dir);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
