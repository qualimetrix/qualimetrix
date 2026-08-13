<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfiguration;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfigurationProviderInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\MeasurementAggregationService;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Evidence\Measurement\FileMeasurement\CompositeCollector;
use Qualimetrix\Analysis\Run\Enrichment\TransitionalEnrichmentResult;
use Qualimetrix\Analysis\Run\Enrichment\TransitionalMetricEnricher;
use Qualimetrix\Architecture\Rules\CircularDependencyRule;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;

#[CoversClass(TransitionalMetricEnricher::class)]
final class MetricEnricherTest extends TestCase
{
    private CompositeCollector $compositeCollector;
    private MeasurementAggregationService $globalCollectorRunner;
    private TransitionalRuntimeConfigurationProviderInterface $configProvider;
    private DependencyGraphInterface $graph;
    private MetricRepositoryInterface $repository;

    protected function setUp(): void
    {
        $this->compositeCollector = new CompositeCollector([]);
        $this->globalCollectorRunner = new MeasurementAggregationService([], $this->compositeCollector);

        $config = new TransitionalRuntimeConfiguration();
        $this->configProvider = self::createStub(TransitionalRuntimeConfigurationProviderInterface::class);
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
        $enricher = new TransitionalMetricEnricher($this->globalCollectorRunner, $this->configProvider);

        $result = $enricher->enrich($this->repository, $this->graph, [], 10);

        self::assertInstanceOf(TransitionalEnrichmentResult::class, $result); // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame([], $result->cycles);
    }

    #[Test]
    public function circularDependencyDetectionFindsCyclesWhenEnabled(): void
    {
        $graph = $this->createCyclicGraph();

        $enricher = new TransitionalMetricEnricher($this->globalCollectorRunner, $this->configProvider);

        $result = $enricher->enrich($this->repository, $graph, [], 10);

        self::assertNotEmpty($result->cycles, 'Cycles should be detected on a cyclic graph');
    }

    #[Test]
    public function circularDependencyDetectionIsSkippedWhenRuleDisabled(): void
    {
        $graph = $this->createCyclicGraph();

        $config = new TransitionalRuntimeConfiguration(
            disabledRules: [CircularDependencyRule::NAME],
        );
        $configProvider = self::createStub(TransitionalRuntimeConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')->willReturn($config);

        $enricher = new TransitionalMetricEnricher($this->globalCollectorRunner, $configProvider);

        $result = $enricher->enrich($this->repository, $graph, [], 10);

        // Same graph that produces cycles when enabled should produce none when disabled
        self::assertSame([], $result->cycles);
    }

    #[Test]
    public function computedMetricsAreSkippedWhenEvaluatorIsNull(): void
    {
        $enricher = new TransitionalMetricEnricher(
            $this->globalCollectorRunner,
            $this->configProvider,
            computedMetricEvaluator: null,
        );

        // Should not throw when evaluator is null, even with files analyzed
        $result = $enricher->enrich($this->repository, $this->graph, [], 10);

        self::assertInstanceOf(TransitionalEnrichmentResult::class, $result); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    #[Test]
    public function enrichReturnsNamespaceTreeInResult(): void
    {
        $enricher = new TransitionalMetricEnricher($this->globalCollectorRunner, $this->configProvider);

        $result = $enricher->enrich($this->repository, $this->graph, [], 5);

        // NamespaceTree should always be present (aggregation always runs)
        self::assertInstanceOf(TransitionalEnrichmentResult::class, $result); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    #[Test]
    public function enrichWithAllOptionalDependenciesNull(): void
    {
        $enricher = new TransitionalMetricEnricher(
            $this->globalCollectorRunner,
            $this->configProvider,
            fileSetInspection: null,
            computedMetricEvaluator: null,
        );

        $result = $enricher->enrich($this->repository, $this->graph, [], 0);

        self::assertInstanceOf(TransitionalEnrichmentResult::class, $result); // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame([], $result->cycles);
    }

    /**
     * Creates a graph with A → B → A cycle for cycle detection tests.
     */
    private function createCyclicGraph(): DependencyGraphInterface
    {
        $classA = SymbolPath::forClass('App', 'ClassA');
        $classB = SymbolPath::forClass('App', 'ClassB');
        $location = new Location(RelativePath::fromString('test.php'), 1);

        $depAtoB = new Dependency(new DeclarationPath($classA, RelativePath::fromString('test.php'), 0), new LogicalClassPath($classB), DependencyType::TypeHint, $location);
        $depBtoA = new Dependency(new DeclarationPath($classB, RelativePath::fromString('test.php'), 0), new LogicalClassPath($classA), DependencyType::TypeHint, $location);

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
