<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfiguration;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfigurationProviderInterface;
use Qualimetrix\Analysis\Configuration\Runtime\TransitionalRuntimeConfigurationHolder;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyTraversalParticipantInterface;
use Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationOptions;
use Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationRule;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicationDetector;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicationResultProvider;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CollectorRuntimeConfigurableInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CollectorRuntimeConfigurationStoreInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\FileMeasurementCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ProjectNamespaceResolverInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Enrichment\TransitionalMetricEnricher;
use Qualimetrix\Analysis\Run\Pipeline\AnalysisPipeline;
use Qualimetrix\Architecture\Rules\CircularDependencyRule;
use Qualimetrix\Configuration\RuleOptionsRegistry;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Violation\ChannelDeclarationRegistryInterface;
use Qualimetrix\Infrastructure\Cache\CacheInterface;
use Qualimetrix\Infrastructure\Console\AnalysisRuntimeConfigurator;
use Qualimetrix\Infrastructure\Console\CheckScopeResolver;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\Command\GraphExportCommand;
use Qualimetrix\Infrastructure\Console\Command\RulesCommand;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Parallel\FileProcessingTaskFactory;
use Qualimetrix\Infrastructure\Parallel\Strategy\AmphpParallelStrategy;
use Qualimetrix\Infrastructure\Parallel\Strategy\StrategySelector;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Qualimetrix\Metrics\Complexity\CognitiveComplexityCollector;
use Qualimetrix\Metrics\Complexity\CyclomaticComplexityCollector;
use Qualimetrix\Metrics\Complexity\NpathComplexityCollector;
use Qualimetrix\Metrics\Halstead\HalsteadCollector;
use Qualimetrix\Metrics\Maintainability\MaintainabilityIndexCollector;
use Qualimetrix\Metrics\Size\ClassCountCollector;
use Qualimetrix\Metrics\Size\LocCollector;
use Qualimetrix\Metrics\Structure\InheritanceDepthCollector;
use Qualimetrix\Metrics\Structure\LcomCollector;
use Qualimetrix\Metrics\Structure\MethodCountCollector;
use Qualimetrix\Metrics\Structure\RfcCollector;
use Qualimetrix\Metrics\Structure\TccLccCollector;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use Qualimetrix\Reporting\GraphProjection\Contract\DependencyGraphProjectionInterface;
use Qualimetrix\Rules\AbstractRule;
use Qualimetrix\Rules\CodeSmell\BooleanArgumentRule;
use Qualimetrix\Rules\CodeSmell\CountInLoopRule;
use Qualimetrix\Rules\CodeSmell\DebugCodeRule;
use Qualimetrix\Rules\CodeSmell\EmptyCatchRule;
use Qualimetrix\Rules\CodeSmell\ErrorSuppressionRule;
use Qualimetrix\Rules\CodeSmell\EvalRule;
use Qualimetrix\Rules\CodeSmell\ExitRule;
use Qualimetrix\Rules\CodeSmell\GotoRule;
use Qualimetrix\Rules\CodeSmell\LongParameterListRule;
use Qualimetrix\Rules\CodeSmell\SuperglobalsRule;
use Qualimetrix\Rules\CodeSmell\UnreachableCodeRule;
use Qualimetrix\Rules\Complexity\CognitiveComplexityRule;
use Qualimetrix\Rules\Complexity\ComplexityRule;
use Qualimetrix\Rules\Complexity\NpathComplexityRule;
use Qualimetrix\Rules\Coupling\CboRule;
use Qualimetrix\Rules\Coupling\ClassRankRule;
use Qualimetrix\Rules\Coupling\DistanceRule;
use Qualimetrix\Rules\Coupling\InstabilityRule;
use Qualimetrix\Rules\Design\TypeCoverageRule;
use Qualimetrix\Rules\Maintainability\MaintainabilityRule;
use Qualimetrix\Rules\Security\CommandInjectionRule;
use Qualimetrix\Rules\Security\SensitiveParameterRule;
use Qualimetrix\Rules\Security\SqlInjectionRule;
use Qualimetrix\Rules\Security\XssRule;
use Qualimetrix\Rules\Size\ClassCountRule;
use Qualimetrix\Rules\Size\MethodCountRule;
use Qualimetrix\Rules\Size\PropertyCountRule;
use Qualimetrix\Rules\Structure\InheritanceRule;
use Qualimetrix\Rules\Structure\LcomRule;
use Qualimetrix\Rules\Structure\NocRule;
use Qualimetrix\Rules\Structure\WmcRule;
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
        $metricEnricher = (new ReflectionProperty(AnalysisPipeline::class, 'metricEnricher'))->getValue($pipeline);
        $fileSetInspection = (new ReflectionProperty(TransitionalMetricEnricher::class, 'fileSetInspection'))
            ->getValue($metricEnricher);
        $participants = (new ReflectionProperty($fileSetInspection, 'participants'))->getValue($fileSetInspection);
        self::assertCount(1, $participants);
        $inspection = $participants[0];
        self::assertInstanceOf(DuplicationDetector::class, $inspection);

        $providerProperty = new ReflectionProperty(DuplicationDetector::class, 'resultProvider');
        self::assertInstanceOf(DuplicationResultProvider::class, $providerProperty->getValue($inspection));

        $rulesCommand = $container->get(RulesCommand::class);
        self::assertInstanceOf(RulesCommand::class, $rulesCommand);
        $rulesProperty = new ReflectionProperty(RulesCommand::class, 'rules');
        $rules = $rulesProperty->getValue($rulesCommand);
        self::assertIsIterable($rules);
        $duplicationRules = [];
        foreach ($rules as $rule) {
            if ($rule instanceof CodeDuplicationRule) {
                $duplicationRules[] = $rule;
            }
        }
        self::assertCount(1, $duplicationRules);
        $optionsProperty = new ReflectionProperty(AbstractRule::class, 'options');
        self::assertInstanceOf(CodeDuplicationOptions::class, $optionsProperty->getValue($duplicationRules[0]));
        $ruleProviderProperty = new ReflectionProperty(CodeDuplicationRule::class, 'resultProvider');
        self::assertSame(
            $providerProperty->getValue($inspection),
            $ruleProviderProperty->getValue($duplicationRules[0]),
        );

        $channels = $container->get(ChannelDeclarationRegistryInterface::class);
        self::assertInstanceOf(ChannelDeclarationRegistryInterface::class, $channels);
        self::assertArrayHasKey(
            CodeDuplicationRule::NAME . '#' . CodeDuplicationRule::NAME,
            $channels->staticDeclarations(),
        );

        $registry = $container->get(RuleRegistryInterface::class);
        self::assertInstanceOf(RuleRegistryInterface::class, $registry);
        self::assertSame(1, \count(array_filter(
            $registry->getClasses(),
            static fn(string $ruleClass): bool => $ruleClass === CodeDuplicationRule::class,
        )));

        $configurationProvider = $container->get(TransitionalRuntimeConfigurationProviderInterface::class);
        self::assertInstanceOf(TransitionalRuntimeConfigurationProviderInterface::class, $configurationProvider);
        $enabledConfiguration = new TransitionalRuntimeConfiguration(
            cacheEnabled: false,
            onlyRules: [CodeDuplicationRule::NAME],
            workers: TransitionalRuntimeConfiguration::WORKERS_SEQUENTIAL,
            projectRoot: AbsolutePath::fromString($this->tempDir),
        );
        $configurationProvider->setConfiguration($enabledConfiguration);
        $configurationProvider->setRuleOptions([
            CodeDuplicationRule::NAME => [
                'min_lines' => 2,
                'min_tokens' => 10,
            ],
        ]);

        $resultProvider = $providerProperty->getValue($inspection);
        $duplicationRule = $duplicationRules[0];
        self::assertSame($resultProvider, $ruleProviderProperty->getValue($duplicationRule));

        self::assertInstanceOf(AnalysisPipeline::class, $pipeline);
        $metricEnricherProperty = new ReflectionProperty(AnalysisPipeline::class, 'metricEnricher');
        $metricEnricher = $metricEnricherProperty->getValue($pipeline);
        self::assertInstanceOf(TransitionalMetricEnricher::class, $metricEnricher);

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

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([]);
        $graph = self::createStub(DependencyGraphInterface::class);
        $graph->method('getAllClasses')->willReturn([]);
        $graph->method('getAllNamespaces')->willReturn([]);
        $graph->method('getAllDependencies')->willReturn([]);
        $duplicateFiles = [new SplFileInfo($firstPath), new SplFileInfo($secondPath)];

        $metricEnricher->enrich($repository, $graph, $duplicateFiles, filesAnalyzed: 0);
        self::assertNotEmpty($resultProvider->all());
        self::assertNotEmpty($duplicationRule->analyze(new AnalysisContext($repository)));

        $configurationProvider->setConfiguration(new TransitionalRuntimeConfiguration(
            cacheEnabled: false,
            disabledRules: [CodeDuplicationRule::NAME],
            workers: TransitionalRuntimeConfiguration::WORKERS_SEQUENTIAL,
            projectRoot: AbsolutePath::fromString($this->tempDir),
        ));
        $metricEnricher->enrich($repository, $graph, $duplicateFiles, filesAnalyzed: 0);

        self::assertSame([], $resultProvider->all());
        self::assertSame([], $duplicationRule->analyze(new AnalysisContext($repository)));

        $configurationProvider->setConfiguration($enabledConfiguration);
        $metricEnricher->enrich($repository, $graph, $duplicateFiles, filesAnalyzed: 0);

        self::assertNotEmpty($resultProvider->all());
        self::assertNotEmpty($duplicationRule->analyze(new AnalysisContext($repository)));

        $metricEnricher->enrich(
            $repository,
            $graph,
            [new SplFileInfo($firstPath)],
            filesAnalyzed: 0,
        );

        self::assertSame([], $resultProvider->all());
        self::assertSame([], $duplicationRule->analyze(new AnalysisContext($repository)));
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

        $store = self::requireCollectorRuntimeConfigurationStore(
            $container->get(CollectorRuntimeConfigurationStoreInterface::class),
        );
        self::assertSame(
            $store,
            (new ReflectionProperty(FileProcessingTaskFactory::class, 'collectorRuntimeConfigurationStore'))
                ->getValue($factory),
        );
        self::assertSame(['lcom_excluded_methods' => []], $store->current()->toPayload());
        $analysisRuntimeConstructor = (new ReflectionClass(AnalysisRuntimeConfigurator::class))->getConstructor();
        $runtimeConstructor = (new ReflectionClass(RuntimeConfigurator::class))->getConstructor();
        self::assertNotNull($analysisRuntimeConstructor);
        self::assertNotNull($runtimeConstructor);
        self::assertCount(7, $analysisRuntimeConstructor->getParameters());
        self::assertCount(7, $runtimeConstructor->getParameters());

        $runtimeCollectors = (new ReflectionProperty($store, 'collectors'))->getValue($store);
        self::assertIsIterable($runtimeCollectors);
        $runtimeCollectors = [...$runtimeCollectors];
        self::assertNotEmpty($runtimeCollectors);
        foreach ($runtimeCollectors as $runtimeCollector) {
            self::assertInstanceOf(CollectorRuntimeConfigurableInterface::class, $runtimeCollector);
        }

        $enricher = (new ReflectionProperty(AnalysisPipeline::class, 'metricEnricher'))->getValue($pipeline);
        $fileSetComposite = (new ReflectionProperty(TransitionalMetricEnricher::class, 'fileSetInspection'))
            ->getValue($enricher);
        $participants = (new ReflectionProperty($fileSetComposite, 'participants'))->getValue($fileSetComposite);
        self::assertCount(1, $participants);
        self::assertInstanceOf(DuplicationDetector::class, $participants[0]);
    }

    private static function requireCollectorRuntimeConfigurationStore(
        object $service,
    ): CollectorRuntimeConfigurationStoreInterface {
        if (!$service instanceof CollectorRuntimeConfigurationStoreInterface) {
            self::fail('Collector runtime configuration store is not wired through its contract.');
        }

        return $service;
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
    public function itInjectsRulesIntoRuleExecutor(): void
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

        // Get RuleOptionsRegistry and configure it
        $ruleOptionsRegistry = $container->get(RuleOptionsRegistry::class);
        self::assertInstanceOf(RuleOptionsRegistry::class, $ruleOptionsRegistry);

        $ruleOptionsRegistry->setCliOptions('cyclomatic-complexity', [
            'warningThreshold' => 20,
            'errorThreshold' => 40,
        ]);

        // Container should still work after configuration
        self::assertTrue($container->isCompiled());
    }

    #[Test]
    public function itAllowsConfiguringConfigurationHolderAtRuntime(): void
    {
        $container = $this->factory->create();

        // Get TransitionalRuntimeConfigurationHolder and configure it
        $configProvider = $container->get(TransitionalRuntimeConfigurationProviderInterface::class);
        self::assertInstanceOf(TransitionalRuntimeConfigurationHolder::class, $configProvider);

        $config = new TransitionalRuntimeConfiguration(
            cacheDir: AbsolutePath::fromString($this->tempDir . '/cache'),
            cacheEnabled: false,
        );
        $configProvider->setConfiguration($config);

        // Verify configuration was applied
        self::assertSame($config, $configProvider->getConfiguration());
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
            \Qualimetrix\Architecture\Rules\LayerViolationRule::class,
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
            TypeCoverageRule::class,
            \Qualimetrix\Rules\Security\HardcodedCredentialsRule::class,
            ClassRankRule::class,
            SqlInjectionRule::class,
            XssRule::class,
            CommandInjectionRule::class,
            SensitiveParameterRule::class,
            \Qualimetrix\Rules\CodeSmell\UnusedPrivateRule::class,
            \Qualimetrix\Rules\CodeSmell\IdenticalSubExpressionRule::class,
            CodeDuplicationRule::class,
            \Qualimetrix\Rules\ComputedMetric\ComputedMetricRule::class,
            \Qualimetrix\Rules\CodeSmell\ConstructorOverinjectionRule::class,
            \Qualimetrix\Rules\Design\DataClassRule::class,
            \Qualimetrix\Rules\Design\GodClassRule::class,
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
