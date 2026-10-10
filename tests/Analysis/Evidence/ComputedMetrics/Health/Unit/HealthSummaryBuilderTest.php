<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Unit;

use LogicException;
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
            new HealthMetricCatalog(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthDecompositionCatalog(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricExpression())),
            $this->defaultDefinitionCatalog(),
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
            classes: [new SymbolInfo(self::exactClassSubject(SymbolPath::forClass('Cont\\A', 'Type'), 'src/Type.php'), RelativePath::fromString('src/Type.php'), 1)],
            namespaceMetrics: [
                // No own declarations: the subtree sum is all it has.
                'ns:Cont' => MetricBag::fromArray(['health.overall' => 40.0, 'size.class-count.sum' => 2]),
                'ns:Cont\\A' => MetricBag::fromArray(['health.overall' => 40.0, 'size.class-count' => 2, 'size.class-count.sum' => 2]),
            ],
        );

        $builder = new HealthSummaryBuilder(
            new HealthMetricCatalog(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthDecompositionCatalog(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricExpression())),
            $this->defaultDefinitionCatalog(),
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
            $first,
            new MetricBag(),
            2,
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
            new HealthMetricCatalog(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthDecompositionCatalog(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricExpression())),
            $this->defaultDefinitionCatalog(),
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
            'size.symbol-class-count' => 2, 'size.symbol-declaring-namespace-count' => 4, 'size.symbol-method-count' => 0,
        ]);
        $repository = new InMemoryMetricRepository();
        $repository->add(SymbolPath::forProject(), $bag, null, null);
        $before = $repository->get(SymbolPath::forProject())->all();
        $scores = (new HealthSummaryBuilder(new HealthMetricCatalog(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthDecompositionCatalog(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricExpression())), $this->defaultDefinitionCatalog()))
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

    #[Test]
    public function itPublishesAllFifteenMeasuredCandidatesBeforeSelection(): void
    {
        $repository = new InMemoryMetricRepository([new MetricDefinition('health.overall', SymbolLevel::Class_), new MetricDefinition('health.complexity', SymbolLevel::Class_), new MetricDefinition('size.class-loc', SymbolLevel::Class_)]);
        $namespaces = [];
        for ($i = 15; $i >= 1; --$i) {
            $namespace = 'App\\N' . str_pad((string) $i, 2, '0', \STR_PAD_LEFT);
            $namespaces[] = $namespace;
            $file = RelativePath::fromString('src/N' . $i . '.php');
            $subject = self::exactClassSubject(SymbolPath::forClass($namespace, 'Type'), $file->value());
            $repository->addSubject($subject, MetricBag::fromArray([
                'health.overall' => (float) $i,
                'health.complexity' => 0.0,
                'size.class-loc' => 0,
            ]), $file, 1);
            $repository->add(SymbolPath::forNamespace($namespace), MetricBag::fromArray([
                'health.overall' => (float) $i,
                'health.complexity' => 0.0,
                'size.class-count' => 0,
                'size.class-count.sum' => 0,
                'size.loc.sum' => 0,
            ]), null, null);
        }
        $summary = (new HealthSummaryBuilder(new HealthMetricCatalog(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthDecompositionCatalog(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricExpression())), $this->defaultDefinitionCatalog()))
            ->build($repository, new NamespaceTree($namespaces), []);
        self::assertCount(15, $summary->worstClasses);
        self::assertCount(15, $summary->worstNamespaces);
        self::assertSame(range(1, 15), array_map(static fn($item): int => (int) $item->healthOverall, $summary->worstClasses));
        self::assertSame(range(1, 15), array_map(static fn($item): int => (int) $item->healthOverall, $summary->worstNamespaces));
        self::assertSame(['complexity' => 0.0], $summary->worstClasses[0]->healthScores);
        self::assertSame(['complexity' => 0.0], $summary->worstNamespaces[0]->healthScores);
        self::assertSame([], $summary->worstNamespaces[0]->metrics);
        self::assertSame('high complexity', $summary->worstClasses[0]->reason);
        $selected = (new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\DrillDown\WorstClassDrillDown())->buildWorstClasses(
            $summary->worstClasses,
            new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\OffenderNamespaceSelection([\Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::exact('App\\N15')]),
        );
        self::assertSame([$summary->worstClasses[14]], $selected);
    }

    #[Test]
    public function itUsesOwnTypeEligibilityAndSubtreeCountsForAParent(): void
    {
        $repository = new InMemoryMetricRepository([new MetricDefinition('health.overall', SymbolLevel::Class_), new MetricDefinition('health.complexity', SymbolLevel::Class_), new MetricDefinition('size.class-loc', SymbolLevel::Class_)]);
        $parentSubject = self::exactClassSubject(SymbolPath::forClass('App\\Parent', 'OwnType'), 'src/OwnType.php');
        $childSubject = self::exactClassSubject(SymbolPath::forClass('App\\Parent\\Child', 'ChildType'), 'src/ChildType.php');
        foreach ([$parentSubject, $childSubject] as $subject) {
            $repository->addSubject($subject, MetricBag::fromArray(['health.overall' => 60.0, 'size.class-loc' => 100]), $subject->declarationPath()?->file, 1);
        }
        foreach (['App', 'App\\Parent', 'App\\Parent\\Child'] as $namespace) {
            $repository->add(SymbolPath::forNamespace($namespace), MetricBag::fromArray([
                'health.overall' => 60.0,
                'size.class-count' => $namespace === 'App' ? 0 : 1,
                'size.class-count.sum' => $namespace === 'App\\Parent\\Child' ? 1 : 2,
                'size.loc.sum' => $namespace === 'App\\Parent\\Child' ? 100 : 200,
            ]), null, null);
        }
        $finding = new Finding(Location::none(), $childSubject, SymbolPath::forFile(RelativePath::fromString('src/ChildType.php')), 'complexity.wmc', 'complexity.wmc', 'Child only', Severity::Warning);
        $summary = (new HealthSummaryBuilder(new HealthMetricCatalog(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthDecompositionCatalog(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricExpression())), $this->defaultDefinitionCatalog()))
            ->build($repository, new NamespaceTree(['App', 'App\\Parent', 'App\\Parent\\Child']), [$finding]);
        self::assertSame(['App\\Parent', 'App\\Parent\\Child'], array_map(static fn($item) => $item->symbolPath->toString(), $summary->worstNamespaces));
        self::assertSame([2, 1], array_map(static fn($item) => $item->classCount, $summary->worstNamespaces));
        self::assertSame([1, 1], array_map(static fn($item) => $item->violationCount, $summary->worstNamespaces));
        self::assertSame([0.5, 1.0], array_map(static fn($item) => $item->violationDensity, $summary->worstNamespaces));
        $counts = [];
        foreach ($summary->worstClasses as $item) {
            $counts[$item->subject->toCanonical()] = $item->violationCount;
        }
        self::assertSame(0, $counts[$parentSubject->toCanonical()]);
        self::assertSame(1, $counts[$childSubject->toCanonical()]);
    }

    #[Test]
    public function itCapturesDefinitionsAndRanksOnceForEachReport(): void
    {
        $file = RelativePath::fromString('src/Type.php');
        $subject = self::exactClassSubject(SymbolPath::forClass('App', 'Type'), $file->value());
        $repository = self::createMock(MetricRepositoryInterface::class);
        $repository->method('get')->willReturn(MetricBag::fromArray([
            'health.overall' => 82.8, 'health.complexity' => 55.0,
            'size.symbol-class-count' => 1, 'size.symbol-method-count' => 1, 'size.symbol-declaring-namespace-count' => 1,
        ]));
        $repository->method('getSubject')->willReturn(MetricBag::fromArray(['health.overall' => 82.8, 'health.complexity' => 55.0]));
        $repository->expects(self::exactly(4))->method('allClassDeclarations')->willReturn([new SymbolInfo($subject, $file, 1)]);
        $repository->method('all')->willReturn([]);
        $repository->method('allCallables')->willReturn([]);
        $definitions = \Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricDefaults::getDefaults();
        $replacement = $definitions;
        foreach (['health.overall' => [90.0, 85.0], 'health.complexity' => [60.0, 40.0]] as $name => [$warning, $error]) {
            $old = $definitions[$name];
            $replacement[$name] = new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition($name, $old->formulas, $old->description, $old->levels, $old->inverted, $warning, $error, $old->applicability);
        }
        $catalog = self::createMock(ComputedMetricDefinitionCatalogInterface::class);
        $catalog->expects(self::exactly(2))->method('all')->willReturnOnConsecutiveCalls(array_values($definitions), array_values($replacement));
        $catalog->expects(self::never())->method('find');
        $builder = new HealthSummaryBuilder(new HealthMetricCatalog(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthDecompositionCatalog(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricExpression())), $catalog);
        $first = $builder->build($repository, new NamespaceTree([]), []);
        $second = $builder->build($repository, new NamespaceTree([]), []);
        self::assertSame([50.0, 30.0], [$first->healthScores['overall']->warningThreshold, $first->healthScores['overall']->errorThreshold]);
        self::assertSame($first->healthScores['overall']->label, $first->worstClasses[0]->label);
        self::assertSame([90.0, 85.0], [$second->healthScores['overall']->warningThreshold, $second->healthScores['overall']->errorThreshold]);
        self::assertSame($second->healthScores['overall']->label, $second->worstClasses[0]->label);
        self::assertSame(60.0, $second->healthScores['complexity']->warningThreshold);
        self::assertSame('Poor', $second->healthScores['complexity']->label);
        self::assertSame([50.0, 30.0], $first->worstClasses[0]->overallThresholds);
        self::assertSame('Excellent', $first->worstClasses[0]->label);
        self::assertSame('', $first->worstClasses[0]->reason);
        self::assertSame([90.0, 85.0], $second->worstClasses[0]->overallThresholds);
        self::assertSame('Critical', $second->worstClasses[0]->label);
        self::assertSame('high complexity', $second->worstClasses[0]->reason);
        self::assertNotSame($first->worstClasses[0], $second->worstClasses[0]);
    }

    #[Test]
    public function itRefusesAnIncompleteHealthDefinitionInsteadOfInventingThresholds(): void
    {
        $catalog = self::createStub(ComputedMetricDefinitionCatalogInterface::class);
        $catalog->method('all')->willReturn([new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition('health.overall', ['class' => '20'], 'Incomplete', [SymbolLevel::Class_])]);
        $repository = new InMemoryMetricRepository([new MetricDefinition('health.overall', SymbolLevel::Class_), new MetricDefinition('health.complexity', SymbolLevel::Class_), new MetricDefinition('size.class-loc', SymbolLevel::Class_)]);
        $subject = self::exactClassSubject(SymbolPath::forClass('App', 'Type'), 'src/Type.php');
        $repository->addSubject($subject, MetricBag::fromArray(['health.overall' => 20.0]), RelativePath::fromString('src/Type.php'), 1);
        self::expectException(LogicException::class);
        self::expectExceptionMessage('Measured health score requires configured thresholds: health.overall');
        (new HealthSummaryBuilder(new HealthMetricCatalog(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthDecompositionCatalog(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricExpression())), $catalog))->build($repository, new NamespaceTree([]), []);
    }

    #[Test]
    public function itOmitsAbsentOverallButRetainsMeasuredZeroAndAbsentDimensions(): void
    {
        $repository = new InMemoryMetricRepository([
            new MetricDefinition('health.overall', SymbolLevel::Class_),
            new MetricDefinition('health.complexity', SymbolLevel::Class_),
            new MetricDefinition('coupling.cbo', SymbolLevel::Class_),
        ]);
        foreach ([
            'MissingOverall' => ['health.complexity' => 0.0],
            'MeasuredZero' => ['health.overall' => 0.0, 'health.complexity' => 0.0, 'coupling.cbo' => 0],
            'NoDimensions' => ['health.overall' => 20.0],
        ] as $name => $values) {
            $file = RelativePath::fromString('src/' . $name . '.php');
            $subject = self::exactClassSubject(SymbolPath::forClass('App', $name), $file->value());
            $repository->addSubject($subject, MetricBag::fromArray($values), $file, 1);
        }
        $summary = (new HealthSummaryBuilder(new HealthMetricCatalog(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthDecompositionCatalog(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricExpression())), $this->defaultDefinitionCatalog()))
            ->build($repository, new NamespaceTree([]), []);
        self::assertSame(['App\\MeasuredZero', 'App\\NoDimensions'], array_map(static fn($item) => $item->symbolPath->toString(), $summary->worstClasses));
        self::assertSame(0.0, $summary->worstClasses[0]->healthOverall);
        self::assertSame(['complexity' => 0.0], $summary->worstClasses[0]->healthScores);
        self::assertSame(['coupling.cbo' => 0], $summary->worstClasses[0]->metrics);
        self::assertSame([], $summary->worstClasses[1]->healthScores);
    }

}
