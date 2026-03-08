<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Integration\Pipeline;

use AiMessDetector\Analysis\Aggregation\GlobalCollectorRunner;
use AiMessDetector\Analysis\Aggregator\MetricAggregator;
use AiMessDetector\Analysis\Collection\CollectionOrchestratorInterface;
use AiMessDetector\Analysis\Collection\CollectionResult;
use AiMessDetector\Analysis\Collection\Dependency\CircularDependencyDetector;
use AiMessDetector\Analysis\Collection\Dependency\DependencyGraphBuilder;
use AiMessDetector\Analysis\Collection\Metric\CompositeCollector;
use AiMessDetector\Analysis\Discovery\FileDiscoveryInterface;
use AiMessDetector\Analysis\Pipeline\AnalysisPipeline;
use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Analysis\RuleExecution\RuleExecutor;
use AiMessDetector\Configuration\AnalysisConfiguration;
use AiMessDetector\Configuration\ConfigurationProviderInterface;
use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Dependency\DependencyGraphInterface;
use AiMessDetector\Core\Dependency\DependencyType;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleInterface;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\SymbolPath;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Metrics\Coupling\CouplingCollector;
use AiMessDetector\Rules\Architecture\CircularDependencyOptions;
use AiMessDetector\Rules\Architecture\CircularDependencyRule;
use ArrayIterator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Integration tests for AnalysisPipeline.
 *
 * These tests expose known bugs in the pipeline where:
 * 1. dependencyGraph is not passed to AnalysisContext (line 127 of AnalysisPipeline)
 * 2. CircularDependencyDetector is never invoked, so additionalData['cycles'] is empty
 * 3. Global collector MetricDefinitions are not included in MetricAggregator,
 *    so their metrics are never aggregated to namespace/project level
 *
 * All tests are expected to FAIL until the pipeline is fixed.
 */
#[Group('regression')]
final class AnalysisPipelineIntegrationTest extends TestCase
{
    private ConfigurationProviderInterface $configurationProvider;

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
                'App\Service\OrderService',
                'App\Repository\OrderRepository',
                DependencyType::New_,
                new Location('/tmp/OrderService.php', 10),
            ),
        ];

        // Spy rule that captures the context
        $capturedContext = null;
        $spyRule = $this->createMock(RuleInterface::class);
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
        $pipeline->analyze('/tmp/src');

        // Assert: the rule should have received a non-null dependency graph
        self::assertNotNull($capturedContext, 'Rule should have been executed');
        self::assertNotNull(
            $capturedContext->dependencyGraph,
            'AnalysisContext should contain the dependency graph built from collected dependencies. '
            . 'Currently AnalysisPipeline creates AnalysisContext without $dependencyGraph parameter.',
        );
        self::assertInstanceOf(DependencyGraphInterface::class, $capturedContext->dependencyGraph);
    }

    /**
     * Bug: CircularDependencyRule expects additionalData['cycles'] to be populated,
     * but AnalysisPipeline never calls CircularDependencyDetector and never populates
     * additionalData in AnalysisContext.
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
                'Fixtures\CircularDeps\ServiceA',
                'Fixtures\CircularDeps\ServiceB',
                DependencyType::New_,
                new Location('/tmp/ServiceA.php', 10),
            ),
            new Dependency(
                'Fixtures\CircularDeps\ServiceB',
                'Fixtures\CircularDeps\ServiceA',
                DependencyType::New_,
                new Location('/tmp/ServiceB.php', 10),
            ),
        ];

        // Verify the detector itself works (sanity check)
        $graphBuilder = new DependencyGraphBuilder();
        $graph = $graphBuilder->build($dependencies);
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
            '/tmp/ServiceA.php',
            1,
        );
        $repository->add(
            SymbolPath::forClass('Fixtures\CircularDeps', 'ServiceB'),
            new MetricBag(),
            '/tmp/ServiceB.php',
            1,
        );

        $pipeline = $this->createPipelineWithDependencies(
            $dependencies,
            $ruleExecutor,
            $repository,
        );

        // Act
        $result = $pipeline->analyze('/tmp/src');

        // Assert: should find circular dependency violations
        $circularViolations = array_filter(
            $result->violations,
            static fn(Violation $v): bool => $v->ruleName === CircularDependencyRule::NAME,
        );

        self::assertNotEmpty(
            $circularViolations,
            'CircularDependencyRule should produce violations when circular dependencies exist. '
            . 'Currently the pipeline never calls CircularDependencyDetector and never populates '
            . 'additionalData[\'cycles\'] in AnalysisContext.',
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
                'App\Service\OrderService',
                'App\Repository\OrderRepository',
                DependencyType::New_,
                new Location('/tmp/OrderService.php', 10),
            ),
            new Dependency(
                'App\Service\PaymentService',
                'App\Repository\PaymentRepository',
                DependencyType::New_,
                new Location('/tmp/PaymentService.php', 10),
            ),
        ];

        // Pre-populate repository with classes (so CouplingCollector finds them)
        $repository = new InMemoryMetricRepository();
        $repository->add(
            SymbolPath::forClass('App\Service', 'OrderService'),
            (new MetricBag())->with('loc', 50),
            '/tmp/OrderService.php',
            1,
        );
        $repository->add(
            SymbolPath::forClass('App\Service', 'PaymentService'),
            (new MetricBag())->with('loc', 30),
            '/tmp/PaymentService.php',
            1,
        );
        // Ensure namespace symbol exists
        $repository->add(
            SymbolPath::forNamespace('App\Service'),
            new MetricBag(),
            '',
            null,
        );

        // Create the real CouplingCollector as a global collector
        $couplingCollector = new CouplingCollector();
        $globalCollectorRunner = new GlobalCollectorRunner([$couplingCollector]);

        // CompositeCollector has no per-file collectors for this test
        $compositeCollector = new CompositeCollector([]);

        $ruleExecutor = $this->createMock(\AiMessDetector\Analysis\RuleExecution\RuleExecutorInterface::class);
        $ruleExecutor->method('execute')->willReturn([]);

        $pipeline = $this->createPipelineWithGlobalCollectors(
            $dependencies,
            $ruleExecutor,
            $globalCollectorRunner,
            $compositeCollector,
            $repository,
        );

        // Act
        $result = $pipeline->analyze('/tmp/src');

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

    /**
     * Creates a pipeline with mocked discovery and collection that returns the given dependencies.
     */
    /**
     * @param list<\AiMessDetector\Core\Dependency\Dependency> $dependencies
     */
    private function createPipelineWithDependencies(
        array $dependencies,
        \AiMessDetector\Analysis\RuleExecution\RuleExecutorInterface $ruleExecutor,
        ?InMemoryMetricRepository $existingRepository = null,
    ): AnalysisPipeline {
        $discovery = $this->createMock(FileDiscoveryInterface::class);
        $discovery->method('discover')->willReturn(new ArrayIterator([
            new SplFileInfo('/tmp/dummy.php'),
        ]));

        $orchestrator = $this->createMock(CollectionOrchestratorInterface::class);
        $orchestrator->method('collect')->willReturnCallback(
            function (array $files, $repository) use ($dependencies, $existingRepository): CollectionResult {
                // If we have a pre-populated repository, copy its data
                if ($existingRepository !== null) {
                    foreach ($existingRepository->all(SymbolType::Class_) as $info) {
                        $bag = $existingRepository->get($info->symbolPath);
                        $repository->add($info->symbolPath, $bag, $info->file, $info->line);
                    }
                }

                return new CollectionResult(1, 0, $dependencies);
            },
        );

        return new AnalysisPipeline(
            defaultDiscovery: $discovery,
            collectionOrchestrator: $orchestrator,
            compositeCollector: new CompositeCollector([]),
            ruleExecutor: $ruleExecutor,
            configurationProvider: $this->configurationProvider,
            globalCollectorRunner: new GlobalCollectorRunner([]),
        );
    }

    /**
     * Creates a pipeline with specific global collectors.
     */
    /**
     * @param list<\AiMessDetector\Core\Dependency\Dependency> $dependencies
     */
    private function createPipelineWithGlobalCollectors(
        array $dependencies,
        \AiMessDetector\Analysis\RuleExecution\RuleExecutorInterface $ruleExecutor,
        GlobalCollectorRunner $globalCollectorRunner,
        CompositeCollector $compositeCollector,
        InMemoryMetricRepository $existingRepository,
    ): AnalysisPipeline {
        $discovery = $this->createMock(FileDiscoveryInterface::class);
        $discovery->method('discover')->willReturn(new ArrayIterator([
            new SplFileInfo('/tmp/dummy.php'),
        ]));

        $orchestrator = $this->createMock(CollectionOrchestratorInterface::class);
        $orchestrator->method('collect')->willReturnCallback(
            function (array $files, $repository) use ($dependencies, $existingRepository): CollectionResult {
                // Copy pre-populated symbols into the pipeline's repository
                foreach ([SymbolType::Class_, SymbolType::Namespace_] as $type) {
                    foreach ($existingRepository->all($type) as $info) {
                        $bag = $existingRepository->get($info->symbolPath);
                        $repository->add($info->symbolPath, $bag, $info->file, $info->line);
                    }
                }

                return new CollectionResult(1, 0, $dependencies);
            },
        );

        return new AnalysisPipeline(
            defaultDiscovery: $discovery,
            collectionOrchestrator: $orchestrator,
            compositeCollector: $compositeCollector,
            ruleExecutor: $ruleExecutor,
            configurationProvider: $this->configurationProvider,
            globalCollectorRunner: $globalCollectorRunner,
        );
    }

    /**
     * Creates a ConfigurationProvider that allows all rules.
     */
    private function createConfigurationProvider(): ConfigurationProviderInterface
    {
        $config = new AnalysisConfiguration();

        $provider = $this->createMock(ConfigurationProviderInterface::class);
        $provider->method('getRuleOptions')->willReturn([]);
        $provider->method('getConfiguration')->willReturn($config);
        $provider->method('hasConfiguration')->willReturn(true);

        return $provider;
    }
}
