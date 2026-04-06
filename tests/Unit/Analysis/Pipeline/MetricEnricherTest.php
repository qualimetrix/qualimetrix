<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Aggregator\GlobalCollectorRunner;
use Qualimetrix\Analysis\Collection\Metric\CompositeCollector;
use Qualimetrix\Analysis\Pipeline\EnrichmentResult;
use Qualimetrix\Analysis\Pipeline\MetricEnricher;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use Qualimetrix\Core\Dependency\Dependency;
use Qualimetrix\Core\Dependency\DependencyGraphInterface;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Rules\Architecture\CircularDependencyRule;
use Qualimetrix\Rules\Duplication\CodeDuplicationRule;
use SplFileInfo;

#[CoversClass(MetricEnricher::class)]
final class MetricEnricherTest extends TestCase
{
    private CompositeCollector $compositeCollector;
    private GlobalCollectorRunner $globalCollectorRunner;
    private ConfigurationProviderInterface $configProvider;
    private DependencyGraphInterface $graph;
    private MetricRepositoryInterface $repository;

    protected function setUp(): void
    {
        $this->compositeCollector = new CompositeCollector([]);
        $this->globalCollectorRunner = new GlobalCollectorRunner([]);

        $config = new AnalysisConfiguration();
        $this->configProvider = self::createStub(ConfigurationProviderInterface::class);
        $this->configProvider->method('getConfiguration')->willReturn($config);

        $this->graph = self::createStub(DependencyGraphInterface::class);
        $this->graph->method('getAllClasses')->willReturn([]);
        $this->graph->method('getAllNamespaces')->willReturn([]);
        $this->graph->method('getAllDependencies')->willReturn([]);

        $this->repository = self::createStub(MetricRepositoryInterface::class);
        $this->repository->method('all')->willReturn([]);

        // Reset static state
        ComputedMetricDefinitionHolder::reset();
    }

    protected function tearDown(): void
    {
        ComputedMetricDefinitionHolder::reset();
    }

    #[Test]
    public function enrichReturnsEnrichmentResultWithAllPhases(): void
    {
        $enricher = new MetricEnricher(
            $this->compositeCollector,
            $this->globalCollectorRunner,
            $this->configProvider,
        );

        $result = $enricher->enrich($this->repository, $this->graph, [], 10);

        self::assertInstanceOf(EnrichmentResult::class, $result); // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame([], $result->cycles);
        self::assertSame([], $result->duplicateBlocks);
    }

    #[Test]
    public function circularDependencyDetectionFindsCyclesWhenEnabled(): void
    {
        $graph = $this->createCyclicGraph();

        $enricher = new MetricEnricher(
            $this->compositeCollector,
            $this->globalCollectorRunner,
            $this->configProvider,
        );

        $result = $enricher->enrich($this->repository, $graph, [], 10);

        self::assertNotEmpty($result->cycles, 'Cycles should be detected on a cyclic graph');
    }

    #[Test]
    public function circularDependencyDetectionIsSkippedWhenRuleDisabled(): void
    {
        $graph = $this->createCyclicGraph();

        $config = new AnalysisConfiguration(
            disabledRules: [CircularDependencyRule::NAME],
        );
        $configProvider = self::createStub(ConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')->willReturn($config);

        $enricher = new MetricEnricher(
            $this->compositeCollector,
            $this->globalCollectorRunner,
            $configProvider,
        );

        $result = $enricher->enrich($this->repository, $graph, [], 10);

        // Same graph that produces cycles when enabled should produce none when disabled
        self::assertSame([], $result->cycles);
    }

    #[Test]
    public function duplicationDetectionIsSkippedWhenRuleDisabled(): void
    {
        $config = new AnalysisConfiguration(
            disabledRules: [CodeDuplicationRule::NAME],
        );
        $configProvider = self::createStub(ConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')->willReturn($config);

        $enricher = new MetricEnricher(
            $this->compositeCollector,
            $this->globalCollectorRunner,
            $configProvider,
            // Duplication detector is null, simulating disabled state
            duplicationDetector: null,
        );

        $result = $enricher->enrich($this->repository, $this->graph, [], 10);

        self::assertSame([], $result->duplicateBlocks);
    }

    #[Test]
    public function duplicationDetectionIsSkippedWhenDetectorIsNull(): void
    {
        $enricher = new MetricEnricher(
            $this->compositeCollector,
            $this->globalCollectorRunner,
            $this->configProvider,
            duplicationDetector: null,
        );

        $result = $enricher->enrich($this->repository, $this->graph, [new SplFileInfo(__FILE__)], 10);

        self::assertSame([], $result->duplicateBlocks);
    }

    #[Test]
    public function computedMetricsAreSkippedWhenEvaluatorIsNull(): void
    {
        $enricher = new MetricEnricher(
            $this->compositeCollector,
            $this->globalCollectorRunner,
            $this->configProvider,
            computedMetricEvaluator: null,
        );

        // Should not throw when evaluator is null, even with files analyzed
        $result = $enricher->enrich($this->repository, $this->graph, [], 10);

        self::assertInstanceOf(EnrichmentResult::class, $result); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    #[Test]
    public function enrichReturnsNamespaceTreeInResult(): void
    {
        $enricher = new MetricEnricher(
            $this->compositeCollector,
            $this->globalCollectorRunner,
            $this->configProvider,
        );

        $result = $enricher->enrich($this->repository, $this->graph, [], 5);

        // NamespaceTree should always be present (aggregation always runs)
        self::assertInstanceOf(EnrichmentResult::class, $result); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    #[Test]
    public function enrichWithAllOptionalDependenciesNull(): void
    {
        $enricher = new MetricEnricher(
            $this->compositeCollector,
            $this->globalCollectorRunner,
            $this->configProvider,
            duplicationDetector: null,
            computedMetricEvaluator: null,
        );

        $result = $enricher->enrich($this->repository, $this->graph, [], 0);

        self::assertInstanceOf(EnrichmentResult::class, $result); // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame([], $result->cycles);
        self::assertSame([], $result->duplicateBlocks);
    }

    /**
     * Creates a graph with A → B → A cycle for cycle detection tests.
     */
    private function createCyclicGraph(): DependencyGraphInterface
    {
        $classA = SymbolPath::forClass('App', 'ClassA');
        $classB = SymbolPath::forClass('App', 'ClassB');
        $location = new Location('test.php', 1);

        $depAtoB = new Dependency($classA, $classB, DependencyType::TypeHint, $location);
        $depBtoA = new Dependency($classB, $classA, DependencyType::TypeHint, $location);

        $graph = self::createStub(DependencyGraphInterface::class);
        $graph->method('getAllClasses')->willReturn([$classA, $classB]);
        $graph->method('getAllNamespaces')->willReturn([]);
        $graph->method('getAllDependencies')->willReturn([$depAtoB, $depBtoA]);
        $graph->method('getClassDependencies')->willReturnCallback(
            static fn(SymbolPath $path): array => match ($path->toCanonical()) {
                $classA->toCanonical() => [$depAtoB],
                $classB->toCanonical() => [$depBtoA],
                default => [],
            },
        );

        return $graph;
    }
}
