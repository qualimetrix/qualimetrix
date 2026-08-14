<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Integration\Pipeline;

use ArrayIterator;
use PHPUnit\Framework\Attributes\Group;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalResolvedConfiguration;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfiguration;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfigurationProviderInterface;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyAnalysis;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyDetector;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyOptions;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyRule;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluator;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyResolver;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyVisitor;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\MeasurementAggregationService;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\MetricAggregator;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\GlobalContextCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\FileMeasurement\CompositeCollector;
use Qualimetrix\Analysis\Evidence\Measurement\FileMeasurement\DerivedMetricExtractor;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Evidence\Measurement\Runtime\CollectorRuntimeConfigurationStore;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Finding\Rule\InMemoryRuleChannelRegistry;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Finding\RuleExecution;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Analysis\Policy\Inline\Contract\RuleValidatorMapFactory;
use Qualimetrix\Analysis\Policy\Inline\Contract\ThresholdOverrideExtractor;
use Qualimetrix\Analysis\Policy\Inline\Extraction\SourceControlExtractor;
use Qualimetrix\Analysis\Run\Collection\CollectionOrchestrator;
use Qualimetrix\Analysis\Run\Collection\FileProcessor;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionPhaseOutput;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessorInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\FileSetInspection\FileSetInspectionComposite;
use Qualimetrix\Analysis\Run\FileSetInspection\RuleSelectorProducerGate;
use Qualimetrix\Analysis\Run\Pipeline\AnalysisPipeline;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Core\Progress\NullProgressReporter;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Infrastructure\Ast\PhpFileParser;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Parallel\FileProcessingTaskFactory;
use Qualimetrix\Infrastructure\Parallel\Strategy\AmphpParallelStrategy;
use Qualimetrix\Infrastructure\Parallel\Strategy\SequentialStrategy;
use Qualimetrix\Infrastructure\Parallel\Strategy\StrategySelector;
use Qualimetrix\Infrastructure\Parallel\Strategy\WorkerCountDetector;
use Qualimetrix\Metrics\Coupling\CouplingCollector;
use Qualimetrix\Metrics\Size\LocCollector;
use Qualimetrix\Reporting\GraphProjection\Contract\DependencyGraphProjectionInterface;
use Qualimetrix\Reporting\GraphProjection\Contract\GraphProjectionRequest;
use Qualimetrix\Rules\Complexity\ComplexityRule;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;
use Qualimetrix\Tests\Analysis\Run\Support\Pipeline\TestPipelineBuilder;
use SplFileInfo;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Integration tests for AnalysisPipeline.
 *
 * These tests expose known bugs in the pipeline where:
 * 1. dependencyGraph is not passed to AnalysisContext (line 127 of AnalysisPipeline)
 * 2. CircularDependencyDetector is never invoked, so AnalysisContext::$cycles is empty
 * 3. Global collector MetricDefinitions are not included in MetricAggregator,
 *    so their metrics are never aggregated to namespace/project level
 *
 * All tests are expected to FAIL until the pipeline is fixed.
 */
#[Group('regression')]
final class AnalysisPipelineIntegrationTest extends TestCase
{
    private TransitionalRuntimeConfigurationProviderInterface $configurationProvider;

    protected function setUp(): void
    {
        $this->configurationProvider = $this->createConfigurationProvider();
    }

    /**
     * Bug: AnalysisPipeline builds a DependencyGraph ($graph) but never passes it
     * to AnalysisContext. Line 127:
     *   $context = new AnalysisContext($repository, $this->configurationProvider->getRuleOptions());
     * Missing: $dependencyGraph parameter.
     *
     * This test uses a spy rule that captures the AnalysisContext and asserts
     * that dependencyGraph is not null when dependencies exist.
     */
    #[Test]
    public function dependencyGraphIsPassedToAnalysisContext(): void
    {
        // Arrange: create dependencies between two classes
        $dependencies = [
            new Dependency(
                new DeclarationPath(SymbolPath::fromClassFqn('App\Service\OrderService'), RelativePath::fromString('tmp/OrderService.php'), 0),
                new LogicalClassPath(SymbolPath::fromClassFqn('App\Repository\OrderRepository')),
                DependencyType::New_,
                new Location(RelativePath::fromString('tmp/OrderService.php'), 10),
            ),
        ];

        // Spy rule that captures the context
        $capturedContext = null;
        $spyRule = self::createStub(RuleInterface::class);
        $spyRule->method('getName')->willReturn('test.spy');
        $spyRule->method('analyze')->willReturnCallback(
            function (AnalysisContext $context) use (&$capturedContext): array {
                $capturedContext = $context;

                return [];
            },
        );

        $ruleExecutor = new RuleExecution([$spyRule], new RuleOptionsRegistry());

        $pipeline = $this->createPipelineWithDependencies(
            $dependencies,
            $ruleExecutor,
        );

        // Act
        $pipeline->analyze(AbsolutePath::fromString('/tmp/src'));

        // Assert: the rule should have received a non-null dependency graph
        self::assertNotNull($capturedContext, 'Rule should have been executed');
        self::assertNotNull(
            $capturedContext->dependencyGraph,
            'AnalysisContext should contain the dependency graph built from collected dependencies. '
            . 'Currently AnalysisPipeline creates AnalysisContext without $dependencyGraph parameter.',
        );
        self::assertInstanceOf(DependencyGraphInterface::class, $capturedContext->dependencyGraph); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    /**
     * Bug: CircularDependencyRule expects AnalysisContext::$cycles to be populated,
     * but AnalysisPipeline never calls CircularDependencyDetector and never populates
     * cycles in AnalysisContext.
     *
     * This test creates a circular dependency (A -> B -> A) and runs the full pipeline
     * with CircularDependencyRule. It should produce violations but currently won't.
     */
    #[Test]
    public function circularDependencyRuleProducesViolationsForActualCycles(): void
    {
        // Arrange: A circular dependency A -> B -> A
        $dependencies = [
            new Dependency(
                new DeclarationPath(SymbolPath::fromClassFqn('Fixtures\CircularDeps\ServiceA'), RelativePath::fromString('tmp/ServiceA.php'), 0),
                new LogicalClassPath(SymbolPath::fromClassFqn('Fixtures\CircularDeps\ServiceB')),
                DependencyType::New_,
                new Location(RelativePath::fromString('tmp/ServiceA.php'), 10),
            ),
            new Dependency(
                new DeclarationPath(SymbolPath::fromClassFqn('Fixtures\CircularDeps\ServiceB'), RelativePath::fromString('tmp/ServiceB.php'), 0),
                new LogicalClassPath(SymbolPath::fromClassFqn('Fixtures\CircularDeps\ServiceA')),
                DependencyType::New_,
                new Location(RelativePath::fromString('tmp/ServiceB.php'), 10),
            ),
        ];

        // Verify the detector itself works (sanity check)
        $graphBuilder = AdjacencyGraphBuilder::builder();
        $graph = $graphBuilder->build(
            $dependencies,
            array_map(static fn(Dependency $dependency): LogicalClassPath => new LogicalClassPath($dependency->sourceLogical()), $dependencies),
        );
        $detector = new CircularDependencyDetector();
        $cycles = $detector->detect($graph);
        self::assertNotEmpty($cycles, 'Sanity check: CircularDependencyDetector should find cycles');

        // Now run via the full pipeline with CircularDependencyRule.
        $analysis = new CircularDependencyAnalysis($detector);
        $rule = new CircularDependencyRule(new CircularDependencyOptions(enabled: true), $analysis);
        $ruleExecutor = new RuleExecution([$rule], new RuleOptionsRegistry());

        // Pre-populate the repository with the classes so CouplingCollector can find them
        $repository = new InMemoryMetricRepository();
        $repository->add(
            SymbolPath::forClass('Fixtures\CircularDeps', 'ServiceA'),
            new MetricBag(),
            RelativePath::fromString('tmp/ServiceA.php'),
            1,
        );
        $repository->add(
            SymbolPath::forClass('Fixtures\CircularDeps', 'ServiceB'),
            new MetricBag(),
            RelativePath::fromString('tmp/ServiceB.php'),
            1,
        );

        $pipeline = $this->createPipelineWithDependencies(
            $dependencies,
            $ruleExecutor,
            $repository,
            $analysis,
        );

        // Act
        $result = $pipeline->analyze(AbsolutePath::fromString('/tmp/src'));

        // Assert: should find circular dependency violations
        $circularViolations = array_filter(
            $result->violations,
            static fn(Violation $v): bool => $v->ruleName === CircularDependencyRule::NAME,
        );

        self::assertNotEmpty(
            $circularViolations,
            'CircularDependencyRule should produce violations when circular dependencies exist. '
            . 'Currently the pipeline never calls CircularDependencyDetector and never populates '
            . 'the $cycles property in AnalysisContext.',
        );
    }

    #[Test]
    public function itResetsArchitectureAndCircularStateIndependentlyAcrossCompiledContainerRuns(): void
    {
        $fixtureRoot = sys_get_temp_dir() . '/qmx-p4-sequential-' . uniqid('', true);
        $cyclicRoot = $fixtureRoot . '/cyclic';
        $cleanRoot = $fixtureRoot . '/clean';
        mkdir($cyclicRoot, 0o755, true);
        mkdir($cleanRoot, 0o755, true);
        file_put_contents(
            $cyclicRoot . '/Controller.php',
            "<?php\nnamespace P4Reset\\First\\Controller;\nfinal class A { public function __construct(private readonly \\P4Reset\\First\\Repository\\B \$b) {} }\n",
        );
        file_put_contents(
            $cyclicRoot . '/Repository.php',
            "<?php\nnamespace P4Reset\\First\\Repository;\nfinal class B { public function __construct(private readonly \\P4Reset\\First\\Controller\\A \$a) {} }\n",
        );
        file_put_contents(
            $cleanRoot . '/Independent.php',
            "<?php\nnamespace P4Reset\\Second;\nfinal class Independent {}\n",
        );

        $container = (new ContainerFactory())->create();
        $runtimeConfigurator = $container->get(RuntimeConfigurator::class);
        $pipeline = $container->get(AnalysisPipelineInterface::class);
        $checkCommand = $container->get(CheckCommand::class);
        self::assertInstanceOf(RuntimeConfigurator::class, $runtimeConfigurator);
        self::assertInstanceOf(AnalysisPipelineInterface::class, $pipeline);
        self::assertInstanceOf(CheckCommand::class, $checkCommand);

        $architectureDocument = new ConfigurationDocument([['architecture' => [
            'layers' => [
                ['name' => 'controller', 'patterns' => ['P4Reset\\First\\Controller\\**']],
                ['name' => 'repository', 'patterns' => ['P4Reset\\First\\Repository\\**']],
            ],
            'allow' => ['controller' => [], 'repository' => []],
            'coverage' => 'ignore',
        ]]]);

        /**
         * @return array{\Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult, array<string, array{total: float, count: int, avg: float, memory: int, peak_memory: int}>}
         */
        $run = static function (
            string $path,
            ConfigurationDocument $document,
            string ...$disabledRules,
        ) use ($fixtureRoot, $runtimeConfigurator, $pipeline, $checkCommand): array {
            $runtime = new TransitionalRuntimeConfiguration(
                cacheEnabled: false,
                workers: TransitionalRuntimeConfiguration::WORKERS_SEQUENTIAL,
                projectRoot: AbsolutePath::fromString($fixtureRoot),
            );
            $resolved = new TransitionalResolvedConfiguration(
                paths: [$path],
                pathExcludes: [],
                runtime: $runtime,
                ruleOptions: [],
                document: $document,
                ruleSelection: new RuleSelection(disabled: array_values($disabledRules)),
            );
            $input = new ArrayInput(['--profile' => true], $checkCommand->getDefinition());
            $runtimeConfigurator->configure($resolved, $input, new BufferedOutput());
            $result = $pipeline->analyze(AbsolutePath::fromString($path));

            return [$result, ProfilerHolder::get()->getSummary()];
        };

        try {
            [$first] = $run($cyclicRoot, $architectureDocument);
            self::assertNotEmpty(self::violationsNamed($first->violations, LayerViolationRule::NAME));
            self::assertNotEmpty(self::violationsNamed($first->violations, CircularDependencyRule::NAME));

            [$second] = $run($cleanRoot, new ConfigurationDocument([]));
            self::assertSame([], self::violationsNamed($second->violations, LayerViolationRule::NAME));
            self::assertSame([], self::violationsNamed($second->violations, CircularDependencyRule::NAME));

            [$withoutCycles, $cycleDisabledSpans] = $run(
                $cyclicRoot,
                $architectureDocument,
                CircularDependencyRule::NAME,
            );
            self::assertNotEmpty(self::violationsNamed($withoutCycles->violations, LayerViolationRule::NAME));
            self::assertSame([], self::violationsNamed($withoutCycles->violations, CircularDependencyRule::NAME));
            self::assertArrayHasKey('architecture-prepare', $cycleDisabledSpans);
            self::assertArrayNotHasKey('cycles', $cycleDisabledSpans);

            [$withoutLayers, $architectureDisabledSpans] = $run(
                $cyclicRoot,
                $architectureDocument,
                LayerViolationRule::NAME,
            );
            self::assertSame([], self::violationsNamed($withoutLayers->violations, LayerViolationRule::NAME));
            self::assertNotEmpty(self::violationsNamed($withoutLayers->violations, CircularDependencyRule::NAME));
            self::assertArrayNotHasKey('architecture-prepare', $architectureDisabledSpans);
            self::assertArrayHasKey('cycles', $architectureDisabledSpans);
        } finally {
            ProfilerHolder::reset();
            self::removeFixtureDirectory($fixtureRoot);
        }
    }

    /**
     * Bug: MetricAggregator only collects MetricDefinitions from MetricCollectorInterface
     * (regular per-file collectors), NOT from GlobalContextCollectorInterface.
     *
     * CouplingCollector (a GlobalContextCollectorInterface) defines aggregation strategies
     * for cbo (Sum, Average, Max at namespace level), but these definitions are never
     * passed to the aggregator. So cbo.sum, cbo.avg, cbo.max are never computed
     * at namespace level.
     *
     * This test verifies that global collector metrics are aggregated to namespace level.
     */
    #[Test]
    public function globalCollectorMetricsAreAggregatedToNamespaceLevel(): void
    {
        // Arrange: two classes in the same namespace with cross-namespace dependencies
        $dependencies = [
            new Dependency(
                new DeclarationPath(SymbolPath::fromClassFqn('App\Service\OrderService'), RelativePath::fromString('tmp/OrderService.php'), 0),
                new LogicalClassPath(SymbolPath::fromClassFqn('App\Repository\OrderRepository')),
                DependencyType::New_,
                new Location(RelativePath::fromString('tmp/OrderService.php'), 10),
            ),
            new Dependency(
                new DeclarationPath(SymbolPath::fromClassFqn('App\Service\PaymentService'), RelativePath::fromString('tmp/PaymentService.php'), 0),
                new LogicalClassPath(SymbolPath::fromClassFqn('App\Repository\PaymentRepository')),
                DependencyType::New_,
                new Location(RelativePath::fromString('tmp/PaymentService.php'), 10),
            ),
        ];

        // Pre-populate repository with classes (so CouplingCollector finds them)
        $repository = new InMemoryMetricRepository();
        $repository->add(
            SymbolPath::forClass('App\Service', 'OrderService'),
            (new MetricBag())->with('loc', 50),
            RelativePath::fromString('tmp/OrderService.php'),
            1,
        );
        $repository->add(
            SymbolPath::forClass('App\Service', 'PaymentService'),
            (new MetricBag())->with('loc', 30),
            RelativePath::fromString('tmp/PaymentService.php'),
            1,
        );
        // Ensure namespace symbol exists
        $repository->add(
            SymbolPath::forNamespace('App\Service'),
            new MetricBag(),
            null,
            null,
        );

        // Create the real CouplingCollector as a global collector
        $couplingCollector = new CouplingCollector(new \Qualimetrix\Core\Coupling\FrameworkNamespacesHolder());

        // CompositeCollector has no per-file collectors for this test.
        $compositeCollector = new CompositeCollector([]);
        $globalCollectorRunner = new MeasurementAggregationService([$couplingCollector], $compositeCollector);

        $ruleExecutor = self::createStub(\Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface::class);
        $ruleExecutor->method('execute')->willReturn([]);

        $pipeline = $this->createPipelineWithGlobalCollectors(
            $dependencies,
            $ruleExecutor,
            $globalCollectorRunner,
            $compositeCollector,
            $repository,
        );

        // Act
        $result = $pipeline->analyze(AbsolutePath::fromString('/tmp/src'));

        // Verify class-level CBO was computed (sanity check)
        $orderServiceBag = $result->metrics->get(
            SymbolPath::forClass('App\Service', 'OrderService'),
        );
        self::assertNotNull(
            $orderServiceBag->get('cbo'),
            'Sanity check: class-level CBO should be computed by CouplingCollector',
        );

        // Now check namespace-level aggregated CBO
        $namespaceBag = $result->metrics->get(SymbolPath::forNamespace('App\Service'));

        // The CouplingCollector defines cbo aggregation at namespace level
        // with Sum, Average, Max strategies. These should produce cbo.sum, cbo.avg, cbo.max.
        $cboSum = $namespaceBag->get('cbo.sum');
        $cboAvg = $namespaceBag->get('cbo.avg');
        $cboMax = $namespaceBag->get('cbo.max');

        self::assertNotNull(
            $cboSum,
            'Namespace-level cbo.sum should be aggregated from class-level CBO metrics. '
            . 'Currently MetricAggregator only collects definitions from MetricCollectorInterface, '
            . 'not from GlobalContextCollectorInterface, so global collector metrics are never aggregated.',
        );
        self::assertNotNull($cboAvg, 'Namespace-level cbo.avg should exist');
        self::assertNotNull($cboMax, 'Namespace-level cbo.max should exist');
    }

    #[Test]
    public function itBuildsEquivalentOrderedDependencyGraphsSequentiallyAndInParallel(): void
    {
        $fixtureRoot = AbsolutePath::fromString(\dirname(__DIR__, 4) . '/Fixtures/CouplingProject')->canonicalize();
        $fixtureFiles = [
            'Core/AbstractEntity.php',
            'Core/EntityInterface.php',
            'Service/OrderService.php',
            'Service/UserService.php',
            'Domain/Order.php',
            'Domain/User.php',
            'Isolated/StandaloneClass.php',
        ];
        $files = array_map(
            static fn(string $file): SplFileInfo => new SplFileInfo($fixtureRoot->value() . '/' . $file),
            $fixtureFiles,
        );
        $universe = array_map(
            static fn(string $fqn): LogicalClassPath => new LogicalClassPath(SymbolPath::fromClassFqn($fqn)),
            [
                'Fixtures\\CouplingProject\\Core\\AbstractEntity',
                'Fixtures\\CouplingProject\\Core\\EntityInterface',
                'Fixtures\\CouplingProject\\Service\\OrderService',
                'Fixtures\\CouplingProject\\Service\\UserService',
                'Fixtures\\CouplingProject\\Domain\\Order',
                'Fixtures\\CouplingProject\\Domain\\User',
                'Fixtures\\CouplingProject\\Isolated\\StandaloneClass',
            ],
        );

        $sequential = self::collectThroughProductionStrategy($files, $fixtureRoot, 0);
        $parallel = self::collectThroughProductionStrategy($files, $fixtureRoot, 2);

        self::assertSame(SequentialStrategy::class, $sequential['strategy']);
        self::assertSame(\count($files), $sequential['mainProcessCalls']);
        self::assertSame(AmphpParallelStrategy::class, $parallel['strategy']);
        self::assertSame(0, $parallel['mainProcessCalls'], 'Parallel collection must not execute the main-process fallback processor');
        self::assertTrue($parallel['workerStarted'], 'Amp worker-start evidence must prove the transport path was reached');
        self::assertNotEmpty($parallel['dependencies'], 'Worker results must survive Amp serialization and deserialization');
        self::assertSame(
            array_map(self::dependencyFields(...), $sequential['dependencies']),
            array_map(self::dependencyFields(...), $parallel['dependencies']),
            'Amp transport must preserve ordered dependency source, target, type, and location fields',
        );

        $container = (new ContainerFactory())->create();
        $builder = $container->get(DependencyGraphBuilderInterface::class);
        self::assertInstanceOf(DependencyGraphBuilderInterface::class, $builder);
        $sequentialGraph = $builder->build($sequential['dependencies'], $universe);
        $parallelGraph = $builder->build($parallel['dependencies'], $universe);

        $isolatedClass = SymbolPath::fromClassFqn('Fixtures\\CouplingProject\\Isolated\\StandaloneClass');
        self::assertSame([], $sequentialGraph->getClassDependencies($isolatedClass));
        self::assertSame([], $sequentialGraph->getClassDependents($isolatedClass));
        self::assertSame(0, $sequentialGraph->getClassCe($isolatedClass));
        self::assertSame(0, $sequentialGraph->getClassCa($isolatedClass));
        $ancestorNamespace = SymbolPath::forNamespace('Fixtures\\CouplingProject');
        self::assertContains(
            $ancestorNamespace->toCanonical(),
            array_map(static fn(SymbolPath $namespace): string => $namespace->toCanonical(), $sequentialGraph->getAllNamespaces()),
        );
        self::assertSame(0, $sequentialGraph->getNamespaceCe($ancestorNamespace));
        self::assertSame(0, $sequentialGraph->getNamespaceCa($ancestorNamespace));

        self::assertSame(
            self::dependencyProjection($sequentialGraph),
            self::dependencyProjection($parallelGraph),
        );
        self::assertSame(
            self::graphProjection($sequentialGraph),
            self::graphProjection($parallelGraph),
        );

        $projector = $container->get(DependencyGraphProjectionInterface::class);
        self::assertInstanceOf(DependencyGraphProjectionInterface::class, $projector);
        self::assertSame(
            $projector->project($sequentialGraph, new GraphProjectionRequest(format: 'dot')),
            $projector->project($parallelGraph, new GraphProjectionRequest(format: 'dot')),
        );

        /** @var array{meta: array{timestamp: string}, statistics: array<string, int>, nodes: list<array<string, string>>, edges: list<array<string, mixed>>} $sequentialJson */
        $sequentialJson = json_decode(
            $projector->project($sequentialGraph, new GraphProjectionRequest(format: 'json')),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        /** @var array{meta: array{timestamp: string}, statistics: array<string, int>, nodes: list<array<string, string>>, edges: list<array<string, mixed>>} $parallelJson */
        $parallelJson = json_decode(
            $projector->project($parallelGraph, new GraphProjectionRequest(format: 'json')),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $sequentialJson['meta']['timestamp']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $parallelJson['meta']['timestamp']);
        unset($sequentialJson['meta']['timestamp'], $parallelJson['meta']['timestamp']);
        self::assertSame($sequentialJson, $parallelJson);
    }

    #[Test]
    public function itProducesIdenticalDependenciesSequentiallyAndThroughARealAmpWorkerRoundTrip(): void
    {
        $fixtureRoot = AbsolutePath::fromString(\dirname(__DIR__, 4) . '/Fixtures/CouplingProject')->canonicalize();
        $files = array_map(
            static fn(string $path): SplFileInfo => new SplFileInfo($fixtureRoot->value() . '/' . $path),
            [
                'Core/AbstractEntity.php',
                'Service/OrderService.php',
                'Domain/Order.php',
            ],
        );

        $sequential = self::collectThroughProductionStrategy($files, $fixtureRoot, 0);
        $parallel = self::collectThroughProductionStrategy($files, $fixtureRoot, 2);

        self::assertSame(SequentialStrategy::class, $sequential['strategy']);
        self::assertSame(AmphpParallelStrategy::class, $parallel['strategy']);
        self::assertTrue($parallel['workerStarted']);
        self::assertNotEmpty($parallel['dependencies']);
        self::assertSame(
            array_map(self::dependencyFields(...), $sequential['dependencies']),
            array_map(self::dependencyFields(...), $parallel['dependencies']),
        );
    }

    #[Test]
    public function itPreservesInlineControlsAcrossARealParallelWorkerRoundTrip(): void
    {
        $fixtureRootPath = sys_get_temp_dir() . '/qmx-inline-worker-' . uniqid('', true);
        mkdir($fixtureRootPath, 0o755, true);
        $fixturePath = $fixtureRootPath . '/Controlled.php';
        file_put_contents($fixturePath, <<<'PHP'
<?php

namespace InlineWorkerFixture;

/**
 * @qmx-ignore complexity.cyclomatic Worker transport parity.
 * @qmx-threshold complexity.cyclomatic warning=15 error=25
 * @qmx-threshold unknown.rule invalid
 */
final class Controlled
{
    public function run(): void {}
}
PHP);

        try {
            $fixtureRoot = AbsolutePath::fromString($fixtureRootPath)->canonicalize();
            $files = [new SplFileInfo($fixturePath)];
            $sequential = self::collectThroughProductionStrategy($files, $fixtureRoot, 0);

            self::assertSame(SequentialStrategy::class, $sequential['strategy']);
            self::assertNotEmpty($sequential['suppressions']);
            self::assertNotEmpty($sequential['thresholdOverrides']);
            self::assertNotEmpty($sequential['thresholdDiagnostics']);

            $parallel = self::collectThroughProductionStrategy($files, $fixtureRoot, 2);
            self::assertSame(AmphpParallelStrategy::class, $parallel['strategy']);
            self::assertTrue($parallel['workerStarted']);
            self::assertEquals($sequential['suppressions'], $parallel['suppressions']);
            self::assertEquals($sequential['thresholdOverrides'], $parallel['thresholdOverrides']);
            self::assertEquals($sequential['thresholdDiagnostics'], $parallel['thresholdDiagnostics']);
        } finally {
            self::removeFixtureDirectory($fixtureRootPath);
        }
    }

    /**
     * Creates a pipeline with mocked discovery and collection that returns the given dependencies.
     */
    /**
     * @param list<\Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency> $dependencies
     */
    private function createPipelineWithDependencies(
        array $dependencies,
        \Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface $ruleExecutor,
        ?InMemoryMetricRepository $existingRepository = null,
        ?CircularDependencyAnalysis $circularDependencyAnalysis = null,
    ): AnalysisPipeline {
        $discovery = self::createStub(FileDiscoveryInterface::class);
        $discovery->method('discover')->willReturn(new ArrayIterator([
            new SplFileInfo('/tmp/dummy.php'),
        ]));

        $orchestrator = self::createStub(CollectionOrchestratorInterface::class);
        $orchestrator->method('collect')->willReturnCallback(
            function (array $files, $repository) use ($dependencies, $existingRepository): CollectionPhaseOutput {
                // If we have a pre-populated repository, copy its data
                if ($existingRepository !== null) {
                    foreach ($existingRepository->all(SymbolType::Class_) as $info) {
                        $bag = $existingRepository->get($info->symbolPath);
                        $repository->add($info->symbolPath, $bag, $info->file, $info->line);
                    }
                }

                return new CollectionPhaseOutput([
                    PathFactory::bestEffortRelative(
                        $files[0]->getPathname(),
                        $this->configurationProvider->getConfiguration()->projectRoot,
                    ),
                ], [], dependencies: $dependencies);
            },
        );

        $fileCollector = new CompositeCollector([]);

        return TestPipelineBuilder::create()
            ->withDefaultDiscovery($discovery)
            ->withCollectionOrchestrator($orchestrator)
            ->withRuleExecution($ruleExecutor)
            ->withConfigurationProvider($this->configurationProvider)
            ->withMeasurementAggregation(new MeasurementAggregationService([], $fileCollector))
            ->withComputedMetricEvaluation(self::createStub(ComputedMetricEvaluator::class))
            ->withCircularDependencyPreparation(
                $circularDependencyAnalysis ?? new CircularDependencyAnalysis(new CircularDependencyDetector()),
            )
            ->withFileSetInspection($this->emptyFileSetInspection())
            ->build();
    }

    /**
     * Creates a pipeline with specific global collectors.
     */
    /**
     * @param list<\Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency> $dependencies
     */
    private function createPipelineWithGlobalCollectors(
        array $dependencies,
        \Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface $ruleExecutor,
        MeasurementAggregationService $globalCollectorRunner,
        CompositeCollector $compositeCollector,
        InMemoryMetricRepository $existingRepository,
    ): AnalysisPipeline {
        $discovery = self::createStub(FileDiscoveryInterface::class);
        $discovery->method('discover')->willReturn(new ArrayIterator([
            new SplFileInfo('/tmp/dummy.php'),
        ]));

        $orchestrator = self::createStub(CollectionOrchestratorInterface::class);
        $orchestrator->method('collect')->willReturnCallback(
            function (array $files, $repository) use ($dependencies, $existingRepository): CollectionPhaseOutput {
                // Copy pre-populated symbols into the pipeline's repository
                foreach ([SymbolType::Class_, SymbolType::Namespace_] as $type) {
                    foreach ($existingRepository->all($type) as $info) {
                        $bag = $existingRepository->get($info->symbolPath);
                        $repository->add($info->symbolPath, $bag, $info->file, $info->line);
                    }
                }

                return new CollectionPhaseOutput([
                    PathFactory::bestEffortRelative(
                        $files[0]->getPathname(),
                        $this->configurationProvider->getConfiguration()->projectRoot,
                    ),
                ], [], dependencies: $dependencies);
            },
        );

        return TestPipelineBuilder::create()
            ->withDefaultDiscovery($discovery)
            ->withCollectionOrchestrator($orchestrator)
            ->withRuleExecution($ruleExecutor)
            ->withConfigurationProvider($this->configurationProvider)
            ->withMeasurementAggregation($globalCollectorRunner)
            ->withComputedMetricEvaluation(self::createStub(ComputedMetricEvaluator::class))
            ->withCircularDependencyPreparation(new CircularDependencyAnalysis(new CircularDependencyDetector()))
            ->withFileSetInspection($this->emptyFileSetInspection())
            ->build();
    }

    private function emptyFileSetInspection(): FileSetInspectionComposite
    {
        return new FileSetInspectionComposite(
            [],
            new RuleSelectorProducerGate(new RuleSelector(new InMemoryRuleChannelRegistry())),
        );
    }

    /**
     * Creates a ConfigurationProvider that allows all rules.
     */
    private function createConfigurationProvider(): TransitionalRuntimeConfigurationProviderInterface
    {
        $config = new TransitionalRuntimeConfiguration();

        $provider = self::createStub(TransitionalRuntimeConfigurationProviderInterface::class);
        $provider->method('getRuleOptions')->willReturn([]);
        $provider->method('getConfiguration')->willReturn($config);
        $provider->method('hasConfiguration')->willReturn(true);

        return $provider;
    }

    /** @return array<int, array<int, string>> */
    private static function dependencyProjection(DependencyGraphInterface $graph): array
    {
        return array_map(
            static fn(Dependency $dependency): array => [
                $dependency->source->toCanonical(),
                $dependency->target->toCanonical(),
                $dependency->type->value,
                $dependency->location->toString(),
            ],
            $graph->getAllDependencies(),
        );
    }

    /** @return array<string, mixed> */
    private static function graphProjection(DependencyGraphInterface $graph): array
    {
        $classes = $graph->getAllClasses();
        $namespaces = $graph->getAllNamespaces();

        return [
            'classes' => array_map(static fn(SymbolPath $path): string => $path->toCanonical(), $classes),
            'namespaces' => array_map(static fn(SymbolPath $path): string => $path->toCanonical(), $namespaces),
            'classDependencies' => array_map(
                static fn(SymbolPath $path): array => array_map(
                    static fn(Dependency $dependency): array => self::dependencyFields($dependency),
                    $graph->getClassDependencies($path),
                ),
                $classes,
            ),
            'classDependents' => array_map(
                static fn(SymbolPath $path): array => array_map(
                    static fn(Dependency $dependency): array => self::dependencyFields($dependency),
                    $graph->getClassDependents($path),
                ),
                $classes,
            ),
            'classCe' => array_map($graph->getClassCe(...), $classes),
            'classCa' => array_map($graph->getClassCa(...), $classes),
            'namespaceCe' => array_map($graph->getNamespaceCe(...), $namespaces),
            'namespaceCa' => array_map($graph->getNamespaceCa(...), $namespaces),
        ];
    }

    /**
     * @return array{string, string, string, string}
     */
    private static function dependencyFields(Dependency $dependency): array
    {
        return [
            $dependency->source->toCanonical(),
            $dependency->target->toCanonical(),
            $dependency->type->value,
            $dependency->location->toString(),
        ];
    }

    /**
     * @param list<Violation> $violations
     *
     * @return list<Violation>
     */
    private static function violationsNamed(array $violations, string $ruleName): array
    {
        return array_values(array_filter(
            $violations,
            static fn(Violation $violation): bool => $violation->ruleName === $ruleName,
        ));
    }

    private static function removeFixtureDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }

        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                self::removeFixtureDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }

    /**
     * @param list<SplFileInfo> $files
     *
     * @return array{
     *     dependencies: list<Dependency>,
     *     strategy: class-string,
     *     mainProcessCalls: int,
     *     workerStarted: bool,
     *     suppressions: array<string, list<\Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression>>,
     *     thresholdOverrides: array<string, list<\Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride>>,
     *     thresholdDiagnostics: array<string, list<\Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic>>
     * }
     */
    private static function collectThroughProductionStrategy(array $files, AbsolutePath $projectRoot, int $workers): array
    {
        $configuration = new TransitionalRuntimeConfiguration(
            cacheEnabled: false,
            workers: $workers,
            projectRoot: $projectRoot,
        );
        $configurationProvider = self::createStub(TransitionalRuntimeConfigurationProviderInterface::class);
        $configurationProvider->method('getConfiguration')->willReturn($configuration);

        $workerStarted = false;
        $logger = self::createStub(\Psr\Log\LoggerInterface::class);
        $logger->method('info')->willReturnCallback(
            static function (string $message, array $context = []) use (&$workerStarted): void {
                if ($message === 'AmphpParallelStrategy: starting parallel processing'
                    && ($context['workers'] ?? null) === 2
                ) {
                    $workerStarted = true;
                }
            },
        );

        $fileProcessingTaskFactory = new FileProcessingTaskFactory(
            new CollectorRuntimeConfigurationStore(),
            DependencyVisitor::class,
            [LocCollector::class],
            [],
            [ComplexityRule::class],
        );
        $parallelStrategy = new AmphpParallelStrategy($fileProcessingTaskFactory, $logger);
        $parallelStrategy->setMinFilesForParallel(1);
        $selector = new StrategySelector(
            $parallelStrategy,
            new SequentialStrategy(),
            $configurationProvider,
            new WorkerCountDetector(),
            $logger,
        );

        $compositeCollector = new CompositeCollector(
            [new LocCollector()],
            [],
            new DependencyVisitor(new DependencyResolver()),
        );
        $productionProcessor = new FileProcessor(
            new PhpFileParser(),
            $compositeCollector,
            sourceControlExtractor: new SourceControlExtractor(
                thresholdOverrideExtractor: new ThresholdOverrideExtractor(
                    RuleValidatorMapFactory::build([ComplexityRule::class]),
                ),
            ),
        );
        $trackingProcessor = new class ($productionProcessor) implements FileProcessorInterface {
            public int $processCalls = 0;

            public function __construct(private readonly FileProcessorInterface $delegate) {}

            public function setProjectRoot(AbsolutePath $projectRoot): void
            {
                $this->delegate->setProjectRoot($projectRoot);
            }

            public function process(SplFileInfo $file): \Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult
            {
                ++$this->processCalls;

                return $this->delegate->process($file);
            }
        };

        $orchestrator = new CollectionOrchestrator(
            $trackingProcessor,
            $selector,
            new DerivedMetricExtractor($compositeCollector),
            new NullProgressReporter(),
            $logger,
        );
        $strategy = $selector->select();
        $output = $orchestrator->collect($files, new InMemoryMetricRepository(), $projectRoot);

        return [
            'dependencies' => $output->dependencies,
            'strategy' => $strategy::class,
            'mainProcessCalls' => $trackingProcessor->processCalls,
            'workerStarted' => $workerStarted,
            'suppressions' => $output->suppressions,
            'thresholdOverrides' => $output->thresholdOverrides,
            'thresholdDiagnostics' => $output->thresholdDiagnostics,
        ];
    }
}
