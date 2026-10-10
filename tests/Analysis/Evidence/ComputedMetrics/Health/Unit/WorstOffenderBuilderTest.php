<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\WorstOffenderBuilder;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
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
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(WorstOffenderBuilder::class)]
final class WorstOffenderBuilderTest extends TestCase
{
    #[Test]
    public function itRetainsTheFindingCountAndDensityOfByteClassNames(): void
    {
        $symbol = SymbolPath::forMethod('App\\Service', "K\xFF", 'run');
        $methodDeclaration = DeclarationPath::of($symbol, RelativePath::fromString("src/K\xFF.php"), DeclarationOrdinal::fromRank(0));
        $finding = new Finding(
            Location::none(),
            MetricSubject::declaration($methodDeclaration),
            $symbol,
            'complexity.ccn',
            'complexity.ccn',
            'Too complex',
            Severity::Warning,
        );
        $repository = new InMemoryMetricRepository();
        $classDeclaration = DeclarationPath::of(SymbolPath::forClass('App\\Service', "K\xFF"), RelativePath::fromString("src/K\xFF.php"), DeclarationOrdinal::fromRank(0));
        $repository->addSubject(MetricSubject::declaration($classDeclaration), new MetricBag(), RelativePath::fromString("src/K\xFF.php"), 1);
        $repository->addCallable(new CallableWithMetrics(
            $methodDeclaration,
            1,
            CallableKind::Method,
            null,
            $classDeclaration,
            $classDeclaration,
            new MetricBag(),
            1,
        ));
        $offenders = $this->buildClassList(
            $repository,
            [$this->snapshot('App\\Service', "K\xFF")],
            \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::subtree('App\\Service'),
            [$finding, $finding],
            60.0,
            40.0,
        );

        self::assertCount(1, $offenders);
        self::assertSame(2, $offenders[0]->violationCount);
        self::assertSame(2.0, $offenders[0]->violationDensity);
    }

    #[Test]
    public function itAttributesAnOwnedMethodOnlyToItsExactClassDeclaration(): void
    {
        $firstFile = RelativePath::fromString('src/First.php');
        $secondFile = RelativePath::fromString('src/Second.php');
        $class = SymbolPath::forClass('App', 'Twin');
        $first = DeclarationPath::of($class, $firstFile, DeclarationOrdinal::fromRank(0));
        $second = DeclarationPath::of($class, $secondFile, DeclarationOrdinal::fromRank(0));
        $method = DeclarationPath::of(SymbolPath::forMethod('App', 'Twin', 'run'), $firstFile, DeclarationOrdinal::fromRank(0));
        $repository = new InMemoryMetricRepository();
        $repository->addSubject(MetricSubject::declaration($first), new MetricBag(), $firstFile, 1);
        $repository->addSubject(MetricSubject::declaration($second), new MetricBag(), $secondFile, 1);
        $repository->addCallable(new CallableWithMetrics(
            $method,
            20,
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
            'Too complex',
            Severity::Warning,
        );

        $offenders = $this->buildClassList(
            $repository,
            [$this->snapshot('App', 'Twin', 'src/First.php', 50), $this->snapshot('App', 'Twin', 'src/Second.php', 200)],
            \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::subtree('App'),
            [$finding],
            60.0,
            40.0,
        );

        self::assertSame([1, 0], array_map(static fn($offender): int => $offender->violationCount, $offenders));
        self::assertSame([2.0, 0.0], array_map(static fn($offender): ?float => $offender->violationDensity, $offenders));
    }

    #[Test]
    public function itRefusesAFindingWithoutItsExactCallableEntry(): void
    {
        $method = SymbolPath::forMethod('App', 'Twin', 'run');
        $finding = new Finding(Location::none(), MetricSubject::declaration(DeclarationPath::of(
            $method,
            RelativePath::fromString('src/Twin.php'),
            DeclarationOrdinal::fromRank(0),
        )), $method, 'complexity.ccn', 'complexity.ccn', 'Too complex', Severity::Warning);

        self::expectException(LogicException::class);
        self::expectExceptionMessage('Missing exact callable metadata');
        (new WorstOffenderBuilder())->countClassFindings(new InMemoryMetricRepository(), [$finding]);
    }

    #[Test]
    public function itCountsAClassFindingAgainstOnlyItsExactSameFileDeclaration(): void
    {
        $file = RelativePath::fromString('src/Twins.php');
        $class = SymbolPath::forClass('App', 'Twin');
        $first = DeclarationPath::of($class, $file, DeclarationOrdinal::fromRank(0));
        $second = DeclarationPath::of($class, $file, DeclarationOrdinal::fromRank(1));
        $finding = new Finding(
            Location::none(),
            MetricSubject::declaration($first),
            $class,
            'size.class-count',
            'size.class-count',
            'Class finding',
            Severity::Warning,
        );

        $offenders = $this->buildClassList(
            new InMemoryMetricRepository(),
            [$this->snapshot('App', 'Twin', 'src/Twins.php', 50), $this->snapshot('App', 'Twin', 'src/Twins.php', 200, 1)],
            \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::subtree('App'),
            [$finding],
            60.0,
            40.0,
        );

        self::assertSame([1, 0], array_map(static fn($offender): int => $offender->violationCount, $offenders));
        self::assertSame([2.0, 0.0], array_map(static fn($offender): ?float => $offender->violationDensity, $offenders));
        self::assertNotSame($first->toCanonical(), $second->toCanonical());
    }

    #[Test]
    public function itAttributesAPropertyHookToItsExactOwner(): void
    {
        $file = RelativePath::fromString('src/Twins.php');
        $class = SymbolPath::forClass('App', 'Twin');
        $first = DeclarationPath::of($class, $file, DeclarationOrdinal::fromRank(0));
        $second = DeclarationPath::of($class, $file, DeclarationOrdinal::fromRank(1));
        $hook = DeclarationPath::of(SymbolPath::forMethod('App', 'Twin', 'get'), $file, DeclarationOrdinal::fromRank(0));
        $repository = new InMemoryMetricRepository();
        $repository->addSubject(MetricSubject::declaration($first), new MetricBag(), $file, 1);
        $repository->addSubject(MetricSubject::declaration($second), new MetricBag(), $file, 20);
        $repository->addCallable(new CallableWithMetrics(
            $hook,
            10,
            CallableKind::PropertyHook,
            null,
            $first,
            $first,
            new MetricBag(),
            10,
        ));
        $finding = new Finding(
            Location::none(),
            MetricSubject::declaration($hook),
            $hook->logical,
            'complexity.ccn',
            'complexity.ccn',
            'Hook finding',
            Severity::Warning,
        );

        self::assertSame([
            MetricSubject::declaration($first)->toCanonical() => 1,
        ], (new WorstOffenderBuilder())->countClassFindings($repository, [$finding]));
    }

    #[Test]
    public function itCountsMethodFindingsForEachSameFileClassDeclarationSeparately(): void
    {
        $file = RelativePath::fromString('src/Twins.php');
        $class = SymbolPath::forClass('App', 'Twin');
        $method = SymbolPath::forMethod('App', 'Twin', 'run');
        $first = DeclarationPath::of($class, $file, DeclarationOrdinal::fromRank(0));
        $second = DeclarationPath::of($class, $file, DeclarationOrdinal::fromRank(1));
        $firstMethod = DeclarationPath::of($method, $file, DeclarationOrdinal::fromRank(0));
        $secondMethod = DeclarationPath::of($method, $file, DeclarationOrdinal::fromRank(1));
        $repository = new InMemoryMetricRepository();
        $repository->addSubject(MetricSubject::declaration($first), new MetricBag(), $file, 1);
        $repository->addSubject(MetricSubject::declaration($second), new MetricBag(), $file, 20);
        foreach ([[$firstMethod, $first, 5], [$secondMethod, $second, 25]] as [$declaration, $owner, $position]) {
            $repository->addCallable(new CallableWithMetrics(
                $declaration,
                $position,
                CallableKind::Method,
                null,
                $owner,
                $owner,
                new MetricBag(),
                $position,
            ));
        }

        $findings = [];
        foreach ([$firstMethod, $secondMethod] as $declaration) {
            $findings[] = new Finding(
                Location::none(),
                MetricSubject::declaration($declaration),
                $method,
                'complexity.ccn',
                'complexity.ccn',
                'Method finding',
                Severity::Warning,
            );
        }

        self::assertSame([
            MetricSubject::declaration($first)->toCanonical() => 1,
            MetricSubject::declaration($second)->toCanonical() => 1,
        ], (new WorstOffenderBuilder())->countClassFindings($repository, $findings));
    }

    #[Test]
    public function itRefusesAnOwnerWithoutItsNamedClassDeclaration(): void
    {
        $file = RelativePath::fromString('src/Twin.php');
        $class = DeclarationPath::of(SymbolPath::forClass('App', 'Twin'), $file, DeclarationOrdinal::fromRank(0));
        $method = DeclarationPath::of(SymbolPath::forMethod('App', 'Twin', 'run'), $file, DeclarationOrdinal::fromRank(0));
        $repository = new InMemoryMetricRepository();
        $repository->addCallable(new CallableWithMetrics(
            $method,
            10,
            CallableKind::Method,
            null,
            $class,
            $class,
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

        self::expectException(LogicException::class);
        self::expectExceptionMessage('Missing named-owner declaration');
        (new WorstOffenderBuilder())->countClassFindings($repository, [$finding]);
    }

    #[Test]
    public function itAbstainsForAnAnonymousClassMethodWithLexicalContextButNoOwner(): void
    {
        $file = RelativePath::fromString('src/Anonymous.php');
        $lexical = DeclarationPath::of(SymbolPath::forClass('App', 'anonymous@1'), $file, DeclarationOrdinal::fromRank(0));
        $method = DeclarationPath::of(SymbolPath::forMethod('App', 'anonymous@1', 'run'), $file, DeclarationOrdinal::fromRank(0));
        $repository = new InMemoryMetricRepository();
        $repository->addCallable(new CallableWithMetrics(
            $method,
            10,
            CallableKind::Method,
            null,
            $lexical,
            null,
            new MetricBag(),
            2,
            true,
        ));
        $finding = new Finding(
            Location::none(),
            MetricSubject::declaration($method),
            $method->logical,
            'complexity.ccn',
            'complexity.ccn',
            'Anonymous method finding',
            Severity::Warning,
        );

        self::assertSame([], (new WorstOffenderBuilder())->countClassFindings($repository, [$finding]));
    }

    #[Test]
    public function itSelectsClassesByNamespaceBoundary(): void
    {
        $offenders = $this->buildClassList(
            new InMemoryMetricRepository(),
            $this->snapshots(),
            \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::subtree('App\\Service'),
            [],
            60.0,
            40.0,
        );

        self::assertSame(['Worker'], $this->typesOf($offenders));
    }

    #[Test]
    public function itSupportsAnExactSelector(): void
    {
        $offenders = $this->buildClassList(
            new InMemoryMetricRepository(),
            $this->snapshots(),
            \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::exact('App\\Service'),
            [],
            60.0,
            40.0,
        );

        self::assertSame(['Worker'], $this->typesOf($offenders));
    }

    #[Test]
    public function itMatchesRegexSelectors(): void
    {
        $offenders = $this->buildClassList(
            new InMemoryMetricRepository(),
            $this->snapshots(),
            \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::regex('App\\\\[^\\\\]+'),
            [],
            60.0,
            40.0,
        );

        self::assertSame(['Worker', 'Bus', 'Other'], $this->typesOf($offenders));
    }

    /**
     * @return list<array{symbol: SymbolInfo, overall: float|null, dimensionScores: array<string, float>, loc: int|float|null, notableMetrics: array<string, int|float>}>
     */
    private function snapshots(): array
    {
        return [
            $this->snapshot('App\\Service', 'Worker'),
            $this->snapshot('App\\ServiceBus', 'Bus'),
            $this->snapshot('App\\Other', 'Other'),
        ];
    }

    /**
     * @return array{symbol: SymbolInfo, overall: float|null, dimensionScores: array<string, float>, loc: int|float|null, notableMetrics: array<string, int|float>}
     */
    private function snapshot(string $namespace, string $class, ?string $file = null, int $loc = 100, int $ordinal = 0): array
    {
        $file ??= 'src/' . $class . '.php';
        return [
            'symbol' => new SymbolInfo(MetricSubject::declaration(DeclarationPath::of(
                SymbolPath::forClass($namespace, $class),
                RelativePath::fromString($file),
                DeclarationOrdinal::fromRank($ordinal),
            )), RelativePath::fromString($file), 1),
            'overall' => 50.0,
            'dimensionScores' => ['complexity' => 50.0],
            'loc' => $loc,
            'notableMetrics' => [],
        ];
    }

    /**
     * @param list<\Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender> $offenders
     *
     * @return list<string|null>
     */
    private function typesOf(array $offenders): array
    {
        return array_map(static fn($offender): ?string => $offender->symbolPath->type, $offenders);
    }
    /**
     * @param list<array{symbol: SymbolInfo, overall: float|null, dimensionScores: array<string, float>, loc: int|float|null, notableMetrics: array<string, int|float>}> $snapshots
     * @param list<Finding> $findings
     *
     * @return list<\Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender>
     */
    private function buildClassList(\Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface $repository, array $snapshots, \Qualimetrix\Core\Pattern\NamespacePattern $namespace, array $findings, float $warning, float $error): array
    {
        $definitions = \Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricDefaults::getDefaults();
        $base = $definitions['health.overall'];
        $definitions['health.overall'] = new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition($base->name, $base->formulas, $base->description, $base->levels, $base->inverted, $warning, $error, $base->applicability);
        $catalog = self::createStub(\Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface::class);
        $catalog->method('all')->willReturn(array_values($definitions));
        $thresholds = \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\OffenderThresholds::fromCatalog($catalog);
        $builder = new WorstOffenderBuilder();
        $counts = $builder->countClassFindings($repository, $findings);
        $offenders = [];
        foreach ($snapshots as $snapshot) {
            $subject = $snapshot['symbol']->subject ?? throw new LogicException('Exact class subject required');
            $offender = $builder->build($snapshot, new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\WorstOffenderEvidence($counts[$subject->toCanonical()] ?? 0, 0), $thresholds);
            if ($offender !== null) {
                $offenders[] = $offender;
            }
        }
        return (new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\DrillDown\WorstClassDrillDown())->buildWorstClasses($offenders, new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\OffenderNamespaceSelection([$namespace]));
    }

}
