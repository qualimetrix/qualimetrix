<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthContributor;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Summary\HealthSummary;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Summary\HealthSummaryBuilder;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthMetricCatalog;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Report;

#[CoversClass(HealthContributor::class)]
#[CoversClass(HealthSummaryBuilder::class)]
final class HealthContributorTest extends TestCase
{
    use MetricRepositoryTestHelper;
    private HealthSummaryBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new HealthSummaryBuilder(
            new HealthMetricCatalog(),
            self::createStub(ComputedMetricDefinitionCatalogInterface::class),
        );
    }

    #[Test]
    public function itContributorVO(): void
    {
        $contributor = new HealthContributor(
            className: 'UserService',
            symbolPath: 'class:App\\Service\\UserService',
            metricValues: ['complexity.ccn.sum' => 15, 'complexity.cognitive.sum' => 12],
        );

        self::assertSame('UserService', $contributor->className);
        self::assertSame('class:App\\Service\\UserService', $contributor->symbolPath);
        self::assertSame(['complexity.ccn.sum' => 15, 'complexity.cognitive.sum' => 12], $contributor->metricValues);
    }

    #[Test]
    public function itComplexityContributorsRankedByHighestCcn(): void
    {
        $report = $this->buildReportWithClasses([
            ['ns' => 'App\\Service', 'name' => 'LowComplexity', 'complexity.ccn' => 2, 'complexity.cognitive' => 1],
            ['ns' => 'App\\Service', 'name' => 'HighComplexity', 'complexity.ccn' => 25, 'complexity.cognitive' => 20],
            ['ns' => 'App\\Service', 'name' => 'MedComplexity', 'complexity.ccn' => 10, 'complexity.cognitive' => 8],
        ]);

        $result = $this->summarize($report);

        self::assertArrayHasKey('complexity', $result->healthScores);
        $contributors = $result->healthScores['complexity']->worstContributors;

        self::assertCount(3, $contributors);
        // Worst first (highest CCN for lower_is_better)
        self::assertSame('HighComplexity', $contributors[0]->className);
        self::assertSame('MedComplexity', $contributors[1]->className);
        self::assertSame('LowComplexity', $contributors[2]->className);

        // Check metric values are included (class-level keys are aggregated: ccn.sum, cognitive.sum)
        self::assertSame(25, $contributors[0]->metricValues['complexity.ccn.sum']);
        self::assertSame(20, $contributors[0]->metricValues['complexity.cognitive.sum']);
    }

    #[Test]
    public function itCohesionContributorsRankedByLowestTcc(): void
    {
        $report = $this->buildReportWithClasses([
            ['ns' => 'App', 'name' => 'WellCohesive', 'cohesion.tcc' => 0.9, 'cohesion.lcom' => 1],
            ['ns' => 'App', 'name' => 'PoorlyCohesive', 'cohesion.tcc' => 0.1, 'cohesion.lcom' => 5],
            ['ns' => 'App', 'name' => 'MedCohesive', 'cohesion.tcc' => 0.4, 'cohesion.lcom' => 3],
        ]);

        $result = $this->summarize($report);

        self::assertArrayHasKey('cohesion', $result->healthScores);
        $contributors = $result->healthScores['cohesion']->worstContributors;

        self::assertCount(3, $contributors);
        // Worst first (lowest TCC for higher_is_better)
        self::assertSame('PoorlyCohesive', $contributors[0]->className);
        self::assertSame('MedCohesive', $contributors[1]->className);
        self::assertSame('WellCohesive', $contributors[2]->className);
    }

    #[Test]
    public function itCouplingContributorsRankedByHighestCe(): void
    {
        $report = $this->buildReportWithClasses([
            ['ns' => 'App', 'name' => 'Isolated', 'coupling.ce' => 2, 'coupling.distance' => 0.1],
            ['ns' => 'App', 'name' => 'HighlyCoupled', 'coupling.ce' => 20, 'coupling.distance' => 0.8],
        ]);

        $result = $this->summarize($report);

        self::assertArrayHasKey('coupling', $result->healthScores);
        $contributors = $result->healthScores['coupling']->worstContributors;

        self::assertCount(2, $contributors);
        self::assertSame('HighlyCoupled', $contributors[0]->className);
        self::assertSame(20, $contributors[0]->metricValues['coupling.ce']);
    }

    #[Test]
    public function itMaintainabilityContributorsRankedByLowestMi(): void
    {
        $report = $this->buildReportWithClasses([
            ['ns' => 'App', 'name' => 'WellMaintained', 'maintainability.mi' => 85.0],
            ['ns' => 'App', 'name' => 'HardToMaintain', 'maintainability.mi' => 25.0],
            ['ns' => 'App', 'name' => 'Moderate', 'maintainability.mi' => 55.0],
        ]);

        $result = $this->summarize($report);

        self::assertArrayHasKey('maintainability', $result->healthScores);
        $contributors = $result->healthScores['maintainability']->worstContributors;

        self::assertCount(3, $contributors);
        // Worst first (lowest MI for higher_is_better)
        self::assertSame('HardToMaintain', $contributors[0]->className);
        self::assertSame(25.0, $contributors[0]->metricValues['maintainability.mi']);
        self::assertSame('Moderate', $contributors[1]->className);
        self::assertSame('WellMaintained', $contributors[2]->className);
    }

    #[Test]
    public function itFewerClassesThanLimitShowsAll(): void
    {
        $report = $this->buildReportWithClasses([
            ['ns' => 'App', 'name' => 'OnlyOne', 'complexity.ccn' => 5, 'complexity.cognitive' => 3],
        ]);

        $result = $this->summarize($report);

        $contributors = $result->healthScores['complexity']->worstContributors;
        self::assertCount(1, $contributors);
        self::assertSame('OnlyOne', $contributors[0]->className);
    }

    #[Test]
    public function itClassWithNullMetricSkipped(): void
    {
        // Build manually with one class missing the primary metric
        $classes = [
            new SymbolInfo(SymbolPath::forClass('App', 'HasCcn'), RelativePath::fromString('src/HasCcn.php'), 1),
            new SymbolInfo(SymbolPath::forClass('App', 'NoCcn'), RelativePath::fromString('src/NoCcn.php'), 1),
        ];

        $classMetrics = [
            'class:App\\HasCcn' => MetricBag::fromArray(['complexity.ccn.sum' => 10, 'complexity.cognitive.sum' => 5]),
            'class:App\\NoCcn' => MetricBag::fromArray(['complexity.cognitive.sum' => 3]), // no ccn.sum
        ];

        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'health.complexity' => 50.0,
                'health.overall' => 60.0,
                'complexity.ccn.avg' => 10.0,
            ]),
            classes: $classes,
            classMetrics: $classMetrics,
        );

        $report = new Report(
            findings: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->summarize($report);
        $contributors = $result->healthScores['complexity']->worstContributors;

        self::assertCount(1, $contributors);
        self::assertSame('HasCcn', $contributors[0]->className);
    }

    #[Test]
    public function itTieBreaksByClassNameAlphabetically(): void
    {
        $report = $this->buildReportWithClasses([
            ['ns' => 'App', 'name' => 'Zeta', 'complexity.ccn' => 10, 'complexity.cognitive' => 5],
            ['ns' => 'App', 'name' => 'Alpha', 'complexity.ccn' => 10, 'complexity.cognitive' => 5],
            ['ns' => 'App', 'name' => 'Mu', 'complexity.ccn' => 10, 'complexity.cognitive' => 5],
        ]);

        $result = $this->summarize($report);
        $contributors = $result->healthScores['complexity']->worstContributors;

        self::assertCount(3, $contributors);
        self::assertSame('Alpha', $contributors[0]->className);
        self::assertSame('Mu', $contributors[1]->className);
        self::assertSame('Zeta', $contributors[2]->className);
    }

    #[Test]
    public function itOverallDimensionHasNoContributors(): void
    {
        $report = $this->buildReportWithClasses([
            ['ns' => 'App', 'name' => 'SomeClass', 'complexity.ccn' => 5, 'complexity.cognitive' => 3],
        ]);

        $result = $this->summarize($report);

        self::assertArrayHasKey('overall', $result->healthScores);
        self::assertSame([], $result->healthScores['overall']->worstContributors);
    }

    #[Test]
    public function itContributorSymbolPath(): void
    {
        $report = $this->buildReportWithClasses([
            ['ns' => 'App\\Domain', 'name' => 'SomeService', 'complexity.ccn' => 15, 'complexity.cognitive' => 10],
        ]);

        $result = $this->summarize($report);
        $contributors = $result->healthScores['complexity']->worstContributors;

        self::assertCount(1, $contributors);
        self::assertSame('class:App\\Domain\\SomeService', $contributors[0]->symbolPath);
    }

    private function summarize(Report $report): HealthSummary
    {
        $metrics = $report->metrics ?? throw new LogicException('Metrics are required.');

        return $this->builder->build($metrics, new NamespaceTree($metrics->getNamespaces()), $report->findings);
    }

    /**
     * @param list<array{ns: string, name: string, ccn?: int, cognitive?: int, tcc?: float, lcom?: int, ce?: int, distance?: float, mi?: float}> $classSpecs
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
            $classes[] = new SymbolInfo($symbol, RelativePath::fromString('src/' . $spec['name'] . '.php'), 1);

            $bag = [];

            if (isset($spec['complexity.ccn'])) {
                $bag['complexity.ccn.sum'] = $spec['complexity.ccn'];
                $dimensionMetrics['health.complexity'] ??= 50.0;
                $dimensionMetrics['complexity.ccn.avg'] ??= 5.0;
            }

            if (isset($spec['complexity.cognitive'])) {
                $bag['complexity.cognitive.sum'] = $spec['complexity.cognitive'];
            }

            if (isset($spec['cohesion.tcc'])) {
                $bag['cohesion.tcc'] = $spec['cohesion.tcc'];
                $dimensionMetrics['health.cohesion'] ??= 50.0;
                $dimensionMetrics['cohesion.tcc.avg'] ??= 0.5;
            }

            if (isset($spec['cohesion.lcom'])) {
                $bag['cohesion.lcom'] = $spec['cohesion.lcom'];
            }

            if (isset($spec['coupling.ce'])) {
                $bag['coupling.ce'] = $spec['coupling.ce'];
                $dimensionMetrics['health.coupling'] ??= 50.0;
                $dimensionMetrics['coupling.ce.avg'] ??= 3.0;
            }

            if (isset($spec['coupling.distance'])) {
                $bag['coupling.distance'] = $spec['coupling.distance'];
            }

            if (isset($spec['maintainability.mi'])) {
                $bag['maintainability.mi'] = $spec['maintainability.mi'];
                $dimensionMetrics['health.maintainability'] ??= 50.0;
                $dimensionMetrics['maintainability.mi.avg'] ??= 65.0;
            }

            $classMetrics[$symbol->toCanonical()] = MetricBag::fromArray($bag);
        }

        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray($dimensionMetrics),
            classes: $classes,
            classMetrics: $classMetrics,
        );

        return new Report(
            findings: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            metrics: $metrics,
        );
    }

}
