<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Integration\Pipeline;

use ArrayIterator;
use PHPUnit\Framework\Attributes\Group;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Collection\Dependency\CircularDependencyDetector;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfiguration;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfigurationProviderInterface;
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
use Qualimetrix\Analysis\RuleExecution\RuleExecutor;
use Qualimetrix\Analysis\Run\Collection\CollectionOrchestrator;
use Qualimetrix\Analysis\Run\Collection\FileProcessor;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionPhaseOutput;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessorInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\Enrichment\TransitionalMetricEnricher;
use Qualimetrix\Analysis\Run\Pipeline\AnalysisPipeline;
use Qualimetrix\Architecture\Rules\CircularDependencyOptions;
use Qualimetrix\Architecture\Rules\CircularDependencyRule;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Progress\NullProgressReporter;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleInterface;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\Ast\PhpFileParser;
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
use Qualimetrix\Tests\Analysis\Run\Support\Pipeline\TestPipelineBuilder;
use Qualimetrix\Tests\Support\Dependency\AdjacencyGraphBuilder;
use SplFileInfo;

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

        $ruleExecutor = new RuleExecutor([$spyRule], $this->configurationProvider);

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

        // Now run via the full pipeline with CircularDependencyRule
        $rule = new CircularDependencyRule(new CircularDependencyOptions(enabled: true));
        $ruleExecutor = new RuleExecutor([$rule], $this->configurationProvider);

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

        $ruleExecutor = self::createStub(\Qualimetrix\Analysis\RuleExecution\RuleExecutorInterface::class);
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

    /**
     * Creates a pipeline with mocked discovery and collection that returns the given dependencies.
     */
    /**
     * @param list<\Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency> $dependencies
     */
    private function createPipelineWithDependencies(
        array $dependencies,
        \Qualimetrix\Analysis\RuleExecution\RuleExecutorInterface $ruleExecutor,
        ?InMemoryMetricRepository $existingRepository = null,
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
        $metricEnricher = new TransitionalMetricEnricher(
            new MeasurementAggregationService([], $fileCollector),
            $this->configurationProvider,
        );

        return TestPipelineBuilder::create()
            ->withDefaultDiscovery($discovery)
            ->withCollectionOrchestrator($orchestrator)
            ->withRuleExecutor($ruleExecutor)
            ->withConfigurationProvider($this->configurationProvider)
            ->withMetricEnricher($metricEnricher)
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
        \Qualimetrix\Analysis\RuleExecution\RuleExecutorInterface $ruleExecutor,
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

        $metricEnricher = new TransitionalMetricEnricher($globalCollectorRunner, $this->configurationProvider);

        return TestPipelineBuilder::create()
            ->withDefaultDiscovery($discovery)
            ->withCollectionOrchestrator($orchestrator)
            ->withRuleExecutor($ruleExecutor)
            ->withConfigurationProvider($this->configurationProvider)
            ->withMetricEnricher($metricEnricher)
            ->build();
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
     * @param list<SplFileInfo> $files
     *
     * @return array{
     *     dependencies: list<Dependency>,
     *     strategy: class-string,
     *     mainProcessCalls: int,
     *     workerStarted: bool
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
        $productionProcessor = new FileProcessor(new PhpFileParser(), $compositeCollector);
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
        ];
    }
}
