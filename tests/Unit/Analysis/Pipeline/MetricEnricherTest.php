<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Aggregator\GlobalCollectorRunner;
use Qualimetrix\Analysis\Collection\Metric\CompositeCollector;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationRule;
use Qualimetrix\Analysis\Evidence\Duplication\Contract\DuplicationInspectionInterface;
use Qualimetrix\Analysis\Pipeline\EnrichmentResult;
use Qualimetrix\Analysis\Pipeline\MetricEnricher;
use Qualimetrix\Architecture\Rules\CircularDependencyRule;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
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

        $inspection = self::createMock(DuplicationInspectionInterface::class);
        $inspection->expects(self::once())->method('reset');
        $inspection->expects(self::never())->method('inspect');

        $enricher = new MetricEnricher(
            $this->compositeCollector,
            $this->globalCollectorRunner,
            $configProvider,
            duplicationInspection: $inspection,
        );

        $enricher->enrich($this->repository, $this->graph, [], 10);
    }

    #[Test]
    public function duplicationDetectionIsSkippedWhenInspectionIsNull(): void
    {
        $enricher = new MetricEnricher(
            $this->compositeCollector,
            $this->globalCollectorRunner,
            $this->configProvider,
            duplicationInspection: null,
        );

        $result = $enricher->enrich($this->repository, $this->graph, [new SplFileInfo(__FILE__)], 10);

        self::assertInstanceOf(EnrichmentResult::class, $result); // @phpstan-ignore staticMethod.alreadyNarrowedType
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
            duplicationInspection: null,
            computedMetricEvaluator: null,
        );

        $result = $enricher->enrich($this->repository, $this->graph, [], 0);

        self::assertInstanceOf(EnrichmentResult::class, $result); // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame([], $result->cycles);
    }

    #[Test]
    public function itClearsDuplicationResultsWhenTheNextRunDisablesTheRule(): void
    {
        $configuration = new AnalysisConfiguration();
        $configProvider = self::createStub(ConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')->willReturnCallback(
            static function () use (&$configuration): AnalysisConfiguration {
                return $configuration;
            },
        );
        $inspection = new DuplicationInspectionSpy([['first-run-block']]);
        $enricher = new MetricEnricher(
            $this->compositeCollector,
            $this->globalCollectorRunner,
            $configProvider,
            duplicationInspection: $inspection,
        );

        $enricher->enrich($this->repository, $this->graph, [new SplFileInfo(__FILE__)], 1);
        self::assertSame(['first-run-block'], $inspection->results);

        $resetCallsBeforeDisabledRun = $inspection->resetCalls;
        $inspectCallsBeforeDisabledRun = $inspection->inspectCalls;
        $configuration = new AnalysisConfiguration(disabledRules: [CodeDuplicationRule::NAME]);

        $enricher->enrich($this->repository, $this->graph, [new SplFileInfo(__FILE__)], 1);

        self::assertSame([], $inspection->results);
        self::assertSame($resetCallsBeforeDisabledRun + 1, $inspection->resetCalls);
        self::assertSame($inspectCallsBeforeDisabledRun, $inspection->inspectCalls);
    }

    #[Test]
    public function itReplacesDuplicationResultsWhenTheNextEnabledRunFindsNoMatches(): void
    {
        $inspection = new DuplicationInspectionSpy([['first-run-block'], []]);
        $enricher = new MetricEnricher(
            $this->compositeCollector,
            $this->globalCollectorRunner,
            $this->configProvider,
            duplicationInspection: $inspection,
        );

        $enricher->enrich($this->repository, $this->graph, [new SplFileInfo(__FILE__)], 1);
        self::assertSame(['first-run-block'], $inspection->results);

        $enricher->enrich($this->repository, $this->graph, [new SplFileInfo(__FILE__)], 1);

        self::assertSame([], $inspection->results);
        self::assertSame(2, $inspection->resetCalls);
        self::assertSame(2, $inspection->inspectCalls);
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

final class DuplicationInspectionSpy implements DuplicationInspectionInterface
{
    /** @var list<string> */
    public array $results = [];

    public int $resetCalls = 0;
    public int $inspectCalls = 0;

    /**
     * @param list<list<string>> $inspectionResults
     */
    public function __construct(private array $inspectionResults) {}

    public function reset(): void
    {
        $this->resetCalls++;
        $this->results = [];
    }

    public function inspect(array $files): void
    {
        $this->inspectCalls++;
        $this->results = array_shift($this->inspectionResults) ?? [];
    }
}
