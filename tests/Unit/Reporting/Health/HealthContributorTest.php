<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Reporting\Debt\DebtCalculator;
use Qualimetrix\Reporting\Debt\RemediationTimeRegistry;
use Qualimetrix\Reporting\Health\HealthContributor;
use Qualimetrix\Reporting\Health\MetricHintProvider;
use Qualimetrix\Reporting\Health\SummaryEnricher;
use Qualimetrix\Reporting\Impact\ClassRankResolver;
use Qualimetrix\Reporting\Impact\ImpactCalculator;
use Qualimetrix\Reporting\Report;

#[CoversClass(HealthContributor::class)]
#[CoversClass(SummaryEnricher::class)]
final class HealthContributorTest extends TestCase
{
    private SummaryEnricher $enricher;

    protected function setUp(): void
    {
        $registry = new RemediationTimeRegistry();
        $this->enricher = new SummaryEnricher(
            new DebtCalculator($registry),
            new MetricHintProvider(),
            new ImpactCalculator(new ClassRankResolver(), $registry),
        );
    }

    public function testContributorVO(): void
    {
        $contributor = new HealthContributor(
            className: 'UserService',
            symbolPath: 'class:App\\Service\\UserService',
            metricValues: ['ccn' => 15, 'cognitive' => 12],
        );

        self::assertSame('UserService', $contributor->className);
        self::assertSame('class:App\\Service\\UserService', $contributor->symbolPath);
        self::assertSame(['ccn' => 15, 'cognitive' => 12], $contributor->metricValues);
    }

    public function testComplexityContributorsRankedByHighestCcn(): void
    {
        $report = $this->buildReportWithClasses([
            ['ns' => 'App\\Service', 'name' => 'LowComplexity', 'ccn' => 2, 'cognitive' => 1],
            ['ns' => 'App\\Service', 'name' => 'HighComplexity', 'ccn' => 25, 'cognitive' => 20],
            ['ns' => 'App\\Service', 'name' => 'MedComplexity', 'ccn' => 10, 'cognitive' => 8],
        ]);

        $result = $this->enricher->enrich($report);

        self::assertArrayHasKey('complexity', $result->healthScores);
        $contributors = $result->healthScores['complexity']->worstContributors;

        self::assertCount(3, $contributors);
        // Worst first (highest CCN for lower_is_better)
        self::assertSame('HighComplexity', $contributors[0]->className);
        self::assertSame('MedComplexity', $contributors[1]->className);
        self::assertSame('LowComplexity', $contributors[2]->className);

        // Check metric values are included
        self::assertSame(25, $contributors[0]->metricValues['ccn']);
        self::assertSame(20, $contributors[0]->metricValues['cognitive']);
    }

    public function testCohesionContributorsRankedByLowestTcc(): void
    {
        $report = $this->buildReportWithClasses([
            ['ns' => 'App', 'name' => 'WellCohesive', 'tcc' => 0.9, 'lcom' => 1],
            ['ns' => 'App', 'name' => 'PoorlyCohesive', 'tcc' => 0.1, 'lcom' => 5],
            ['ns' => 'App', 'name' => 'MedCohesive', 'tcc' => 0.4, 'lcom' => 3],
        ]);

        $result = $this->enricher->enrich($report);

        self::assertArrayHasKey('cohesion', $result->healthScores);
        $contributors = $result->healthScores['cohesion']->worstContributors;

        self::assertCount(3, $contributors);
        // Worst first (lowest TCC for higher_is_better)
        self::assertSame('PoorlyCohesive', $contributors[0]->className);
        self::assertSame('MedCohesive', $contributors[1]->className);
        self::assertSame('WellCohesive', $contributors[2]->className);
    }

    public function testCouplingContributorsRankedByHighestCbo(): void
    {
        $report = $this->buildReportWithClasses([
            ['ns' => 'App', 'name' => 'Isolated', 'cbo' => 2, 'distance' => 0.1],
            ['ns' => 'App', 'name' => 'HighlyCoupled', 'cbo' => 20, 'distance' => 0.8],
        ]);

        $result = $this->enricher->enrich($report);

        self::assertArrayHasKey('coupling', $result->healthScores);
        $contributors = $result->healthScores['coupling']->worstContributors;

        self::assertCount(2, $contributors);
        self::assertSame('HighlyCoupled', $contributors[0]->className);
        self::assertSame(20, $contributors[0]->metricValues['cbo']);
    }

    public function testMaintainabilityContributorsRankedByLowestMi(): void
    {
        $report = $this->buildReportWithClasses([
            ['ns' => 'App', 'name' => 'WellMaintained', 'mi' => 85.0],
            ['ns' => 'App', 'name' => 'HardToMaintain', 'mi' => 25.0],
            ['ns' => 'App', 'name' => 'Moderate', 'mi' => 55.0],
        ]);

        $result = $this->enricher->enrich($report);

        self::assertArrayHasKey('maintainability', $result->healthScores);
        $contributors = $result->healthScores['maintainability']->worstContributors;

        self::assertCount(3, $contributors);
        // Worst first (lowest MI for higher_is_better)
        self::assertSame('HardToMaintain', $contributors[0]->className);
        self::assertSame(25.0, $contributors[0]->metricValues['mi']);
        self::assertSame('Moderate', $contributors[1]->className);
        self::assertSame('WellMaintained', $contributors[2]->className);
    }

    public function testFewerClassesThanLimitShowsAll(): void
    {
        $report = $this->buildReportWithClasses([
            ['ns' => 'App', 'name' => 'OnlyOne', 'ccn' => 5, 'cognitive' => 3],
        ]);

        $result = $this->enricher->enrich($report);

        $contributors = $result->healthScores['complexity']->worstContributors;
        self::assertCount(1, $contributors);
        self::assertSame('OnlyOne', $contributors[0]->className);
    }

    public function testClassWithNullMetricSkipped(): void
    {
        // Build manually with one class missing the primary metric
        $classes = [
            new SymbolInfo(SymbolPath::forClass('App', 'HasCcn'), 'src/HasCcn.php', 1),
            new SymbolInfo(SymbolPath::forClass('App', 'NoCcn'), 'src/NoCcn.php', 1),
        ];

        $classMetrics = [
            'class:App\\HasCcn' => MetricBag::fromArray(['ccn' => 10, 'cognitive' => 5]),
            'class:App\\NoCcn' => MetricBag::fromArray(['cognitive' => 3]), // no ccn
        ];

        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'health.complexity' => 50.0,
                'health.overall' => 60.0,
                'ccn.avg' => 10.0,
            ]),
            classes: $classes,
            classMetrics: $classMetrics,
        );

        $report = new Report(
            violations: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->enricher->enrich($report);
        $contributors = $result->healthScores['complexity']->worstContributors;

        self::assertCount(1, $contributors);
        self::assertSame('HasCcn', $contributors[0]->className);
    }

    public function testTieBreaksByClassNameAlphabetically(): void
    {
        $report = $this->buildReportWithClasses([
            ['ns' => 'App', 'name' => 'Zeta', 'ccn' => 10, 'cognitive' => 5],
            ['ns' => 'App', 'name' => 'Alpha', 'ccn' => 10, 'cognitive' => 5],
            ['ns' => 'App', 'name' => 'Mu', 'ccn' => 10, 'cognitive' => 5],
        ]);

        $result = $this->enricher->enrich($report);
        $contributors = $result->healthScores['complexity']->worstContributors;

        self::assertCount(3, $contributors);
        self::assertSame('Alpha', $contributors[0]->className);
        self::assertSame('Mu', $contributors[1]->className);
        self::assertSame('Zeta', $contributors[2]->className);
    }

    public function testOverallDimensionHasNoContributors(): void
    {
        $report = $this->buildReportWithClasses([
            ['ns' => 'App', 'name' => 'SomeClass', 'ccn' => 5, 'cognitive' => 3],
        ]);

        $result = $this->enricher->enrich($report);

        self::assertArrayHasKey('overall', $result->healthScores);
        self::assertSame([], $result->healthScores['overall']->worstContributors);
    }

    public function testContributorSymbolPath(): void
    {
        $report = $this->buildReportWithClasses([
            ['ns' => 'App\\Domain', 'name' => 'SomeService', 'ccn' => 15, 'cognitive' => 10],
        ]);

        $result = $this->enricher->enrich($report);
        $contributors = $result->healthScores['complexity']->worstContributors;

        self::assertCount(1, $contributors);
        self::assertSame('class:App\\Domain\\SomeService', $contributors[0]->symbolPath);
    }

    /**
     * @param list<array{ns: string, name: string, ccn?: int, cognitive?: int, tcc?: float, lcom?: int, cbo?: int, distance?: float, mi?: float}> $classSpecs
     */
    private function buildReportWithClasses(array $classSpecs): Report
    {
        $classes = [];
        $classMetrics = [];
        $dimensionMetrics = [
            'health.overall' => 60.0,
        ];

        foreach ($classSpecs as $spec) {
            $symbol = SymbolPath::forClass($spec['ns'], $spec['name']);
            $classes[] = new SymbolInfo($symbol, 'src/' . $spec['name'] . '.php', 1);

            $bag = [];

            if (isset($spec['ccn'])) {
                $bag['ccn'] = $spec['ccn'];
                $dimensionMetrics['health.complexity'] ??= 50.0;
                $dimensionMetrics['ccn.avg'] ??= 5.0;
            }

            if (isset($spec['cognitive'])) {
                $bag['cognitive'] = $spec['cognitive'];
            }

            if (isset($spec['tcc'])) {
                $bag['tcc'] = $spec['tcc'];
                $dimensionMetrics['health.cohesion'] ??= 50.0;
                $dimensionMetrics['tcc.avg'] ??= 0.5;
            }

            if (isset($spec['lcom'])) {
                $bag['lcom'] = $spec['lcom'];
            }

            if (isset($spec['cbo'])) {
                $bag['cbo'] = $spec['cbo'];
                $dimensionMetrics['health.coupling'] ??= 50.0;
                $dimensionMetrics['cbo.avg'] ??= 5.0;
            }

            if (isset($spec['distance'])) {
                $bag['distance'] = $spec['distance'];
            }

            if (isset($spec['mi'])) {
                $bag['mi'] = $spec['mi'];
                $dimensionMetrics['health.maintainability'] ??= 50.0;
                $dimensionMetrics['mi.avg'] ??= 65.0;
            }

            $classMetrics[$symbol->toCanonical()] = MetricBag::fromArray($bag);
        }

        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray($dimensionMetrics),
            classes: $classes,
            classMetrics: $classMetrics,
        );

        return new Report(
            violations: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            metrics: $metrics,
        );
    }

    /**
     * @param list<SymbolInfo> $classes
     * @param array<string, MetricBag> $classMetrics
     */
    private function createMetricRepository(
        MetricBag $projectMetrics,
        array $classes = [],
        array $classMetrics = [],
    ): MetricRepositoryInterface {
        $mock = $this->createMock(MetricRepositoryInterface::class);

        $mock->method('get')
            ->willReturnCallback(function (SymbolPath $symbol) use ($projectMetrics, $classMetrics): MetricBag {
                $canonical = $symbol->toCanonical();

                if ($symbol->getType() === SymbolType::Project) {
                    return $projectMetrics;
                }

                if (isset($classMetrics[$canonical])) {
                    return $classMetrics[$canonical];
                }

                return new MetricBag();
            });

        $mock->method('all')
            ->willReturnCallback(function (SymbolType $type) use ($classes): iterable {
                return match ($type) {
                    SymbolType::Class_ => $classes,
                    default => [],
                };
            });

        $mock->method('getNamespaces')
            ->willReturn([]);

        return $mock;
    }
}
