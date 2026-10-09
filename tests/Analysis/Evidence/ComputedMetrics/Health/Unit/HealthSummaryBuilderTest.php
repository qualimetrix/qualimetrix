<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Summary\HealthSummaryBuilder;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthMetricCatalog;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(HealthSummaryBuilder::class)]
final class HealthSummaryBuilderTest extends TestCase
{
    use MetricRepositoryTestHelper;

    #[Test]
    public function itEnrichesWithHealthScores(): void
    {
        $classPath = SymbolPath::forClass('App', 'Service');
        $classSubject = MetricSubject::declaration(DeclarationPath::of(
            $classPath,
            RelativePath::fromString('src/Service.php'),
            DeclarationOrdinal::fromRank(0),
        ));
        $classInfo = new SymbolInfo($classSubject, RelativePath::fromString('src/Service.php'), null);
        $classBag = MetricBag::fromArray([
            'complexity.ccn.sum' => 12,
            'complexity.cognitive.sum' => 8,
        ]);
        $projectMetrics = MetricBag::fromArray([
            'health.complexity' => 65.0,
            'health.cohesion' => 45.0,
            'health.coupling' => 80.0,
            'health.typing' => 90.0,
            'health.maintainability' => 58.0,
            'health.overall' => 72.0,
            'complexity.ccn.avg' => 8.2,
            'complexity.cognitive.avg' => 6.1,
            'cohesion.tcc.avg' => 0.15,
            'cohesion.lcom.avg' => 4.0,
            // Written from the symbol list by every run; each health
            // coverage divides by one of them.
            'size.symbol-class-count' => 1,
            'size.symbol-method-count' => 2,
            'size.symbol-declaring-namespace-count' => 1,
        ]);
        $metrics = self::createStub(MetricRepositoryInterface::class);
        $metrics->method('get')->willReturn($projectMetrics);
        $metrics->method('all')->willReturn([]);
        $metrics->method('allClassDeclarations')->willReturn([$classInfo]);
        $metrics->method('getSubject')->willReturn($classBag);
        $builder = new HealthSummaryBuilder(
            new HealthMetricCatalog(),
            self::createStub(ComputedMetricDefinitionCatalogInterface::class),
        );

        $result = $builder->build($metrics, new NamespaceTree([]), []);

        self::assertCount(6, $result->healthScores);
        self::assertArrayHasKey('complexity', $result->healthScores);
        self::assertArrayHasKey('cohesion', $result->healthScores);
        self::assertArrayHasKey('overall', $result->healthScores);
        $complexity = $result->healthScores['complexity'];
        self::assertSame('complexity', $complexity->name);
        self::assertSame(65.0, $complexity->score);
        self::assertSame('Fair', $complexity->label);
        self::assertCount(5, $complexity->decomposition);
        self::assertNull($complexity->decomposition[2]->value);
        self::assertSame(0, $complexity->decomposition[2]->coverage->measured);
        self::assertSame('complexity.ccn.avg', $complexity->decomposition[0]->metricKey);
        self::assertSame('complexity.cognitive.avg', $complexity->decomposition[1]->metricKey);
        self::assertCount(1, $complexity->worstContributors);
        self::assertSame(
            ['complexity.ccn.sum' => 12, 'complexity.cognitive.sum' => 8],
            $complexity->worstContributors[0]->metricValues,
        );
        $cohesion = $result->healthScores['cohesion'];
        self::assertSame(45.0, $cohesion->score);
        self::assertSame('Poor', $cohesion->label);
        self::assertCount(2, $cohesion->decomposition);
        self::assertSame('cohesion.tcc.avg', $cohesion->decomposition[0]->metricKey);
        self::assertSame(0.15, $cohesion->decomposition[0]->value);
        self::assertSame('cohesion.lcom.avg', $cohesion->decomposition[1]->metricKey);
        self::assertSame('Fair', $result->healthScores['maintainability']->label);
    }

    /**
     * A container namespace holding no declarations of its own is not a worst
     * offender: the score it publishes is its subtree's, and ranking it beside
     * its own children double-counts them. The guard asked
     * `size.class-count.sum`, which is the subtree count and therefore positive
     * for exactly the containers it meant to exclude.
     */
    #[Test]
    public function itRanksANamespaceOnlyWhenItDeclaresClassesOfItsOwn(): void
    {
        $container = SymbolPath::forNamespace('Cont');
        $child = SymbolPath::forNamespace('Cont\\A');
        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray(['health.overall' => 72.0]),
            namespaces: [
                new SymbolInfo($container, RelativePath::fromString('src/Cont'), null),
                new SymbolInfo($child, RelativePath::fromString('src/Cont/A'), null),
            ],
            namespaceMetrics: [
                // No own declarations: the subtree sum is all it has.
                'ns:Cont' => MetricBag::fromArray(['health.overall' => 40.0, 'size.class-count.sum' => 2]),
                'ns:Cont\\A' => MetricBag::fromArray(['health.overall' => 40.0, 'size.class-count' => 2, 'size.class-count.sum' => 2]),
            ],
        );

        $builder = new HealthSummaryBuilder(
            new HealthMetricCatalog(),
            self::createStub(ComputedMetricDefinitionCatalogInterface::class),
        );

        $ranked = array_map(
            static fn(object $offender): string => $offender->symbolPath->toCanonical(),
            $builder->build($metrics, new NamespaceTree(['Cont', 'Cont\\A']), [])->worstNamespaces,
        );

        self::assertSame(['ns:Cont\\A'], $ranked);
    }

    #[Test]
    public function itCountsAnOwnedFindingOnlyForItsExactDuplicateClass(): void
    {
        $firstFile = RelativePath::fromString('src/First.php');
        $secondFile = RelativePath::fromString('src/Second.php');
        $class = SymbolPath::forClass('App', 'Twin');
        $first = DeclarationPath::of($class, $firstFile, DeclarationOrdinal::fromRank(0));
        $second = DeclarationPath::of($class, $secondFile, DeclarationOrdinal::fromRank(0));
        $method = DeclarationPath::of(SymbolPath::forMethod('App', 'Twin', 'run'), $firstFile, DeclarationOrdinal::fromRank(0));
        $repository = new InMemoryMetricRepository([
            new MetricDefinition('health.overall', SymbolLevel::Class_),
            new MetricDefinition('size.class-loc', SymbolLevel::Class_),
        ]);
        $repository->addSubject(MetricSubject::declaration($first), MetricBag::fromArray([
            'health.overall' => 30.0, 'size.class-loc' => 50,
        ]), $firstFile, 1);
        $repository->addSubject(MetricSubject::declaration($second), MetricBag::fromArray([
            'health.overall' => 40.0, 'size.class-loc' => 200,
        ]), $secondFile, 1);
        $repository->addCallable(new CallableWithMetrics(
            $method,
            10,
            CallableKind::Method,
            null,
            $first,
            new LogicalClassPath($class),
            new MetricBag(),
            2,
            $first,
        ));
        $finding = new Finding(
            Location::none(),
            MetricSubject::declaration($method),
            $method->logical,
            'complexity.ccn',
            'complexity.ccn',
            'Method finding',
            Severity::Warning,
        );

        $summary = (new HealthSummaryBuilder(
            new HealthMetricCatalog(),
            self::createStub(ComputedMetricDefinitionCatalogInterface::class),
        ))->build($repository, new NamespaceTree([]), [$finding]);

        self::assertSame([1, 0], array_map(static fn($offender): int => $offender->violationCount, $summary->worstClasses));
        self::assertSame([2.0, 0.0], array_map(static fn($offender): ?float => $offender->violationDensity, $summary->worstClasses));
    }

    #[Test]
    public function itKeepsExpectedMissingInputsNullableWithoutWritingRepositoryScalars(): void
    {
        $bag = MetricBag::fromArray([
            'health.cohesion' => 80.0, 'cohesion.lcom.avg' => 0, 'cohesion.lcom.count' => 2,
            'health.coupling' => 60.0, 'coupling.cbo.avg' => 0, 'coupling.cbo.count' => 2,
            'size.symbol-class-count' => 2, 'size.symbol-declaring-namespace-count' => 4,
        ]);
        $repository = new InMemoryMetricRepository();
        $repository->add(SymbolPath::forProject(), $bag, null, null);
        $before = $repository->get(SymbolPath::forProject())->all();
        $scores = (new HealthSummaryBuilder(new HealthMetricCatalog(), self::createStub(ComputedMetricDefinitionCatalogInterface::class)))
            ->build($repository, new NamespaceTree([]), [])->healthScores;
        foreach (['cohesion' => ['cohesion.tcc.avg', 2], 'coupling' => ['coupling.distance-own.avg', 4]] as $dimension => [$key, $eligible]) {
            $missing = $scores[$dimension]->decomposition[0];
            self::assertSame($key, $missing->metricKey);
            self::assertNull($missing->value);
            self::assertSame('not-measured', $missing->coverage->state);
            self::assertSame(0, $missing->coverage->measured);
            self::assertSame($eligible, $missing->coverage->eligible);
            self::assertSame(0.0, $scores[$dimension]->decomposition[1]->value);
            self::assertSame('measured', $scores[$dimension]->decomposition[1]->coverage->state);
        }
        self::assertSame($before, $repository->get(SymbolPath::forProject())->all());
        self::assertFalse($repository->get(SymbolPath::forProject())->has('cohesion.tcc.avg'));
        self::assertFalse($repository->get(SymbolPath::forProject())->has('coupling.distance-own.avg'));
    }

}
