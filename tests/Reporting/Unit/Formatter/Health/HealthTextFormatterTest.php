<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit\Formatter\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\DrillDown\HealthScoreDrillDown;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\CoverageUnit;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\DecompositionItem;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthContributor;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthCoverage;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthScore;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthMetricCatalog;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Reporting\CoverageFailure;
use Qualimetrix\Reporting\Formatter\Health\HealthTextFormatter;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Health\HealthScoreResolver;
use Qualimetrix\Reporting\Report;
use Qualimetrix\Reporting\ReportBuilder;
use Qualimetrix\Reporting\ReportCoverage;

#[CoversClass(HealthTextFormatter::class)]
final class HealthTextFormatterTest extends TestCase
{
    private HealthTextFormatter $formatter;

    protected function setUp(): void
    {
        $hintProvider = new HealthMetricCatalog();
        $drillDown = new HealthScoreDrillDown(self::createStub(ComputedMetricDefinitionCatalogInterface::class));
        $resolver = new HealthScoreResolver($drillDown);
        $this->formatter = new HealthTextFormatter($resolver);
    }

    #[Test]
    public function itPublishesPopulationBeforeEmptyDataReturnsAndKeepsReasonsVerbose(): void
    {
        $trace = new \Qualimetrix\Analysis\Finding\Population\PopulationTrace();
        $trace->record('fixture.rule', new \Qualimetrix\Analysis\Finding\Contract\FindingChannel('fixture.channel'), \Qualimetrix\Core\Symbol\SymbolLevel::Project, \Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity::selector('project:fixture', 'project'), 'published', 'The fixture value is absent.');
        $report = new \Qualimetrix\Reporting\Report([], 0, 0, 0, 0, 0, population: $trace->freeze());
        $compact = $this->formatter->format($report, new FormatterContext(useColor: false))->body;
        self::assertSame(1, substr_count($compact, 'Rule population incomplete'));
        self::assertStringContainsString('project: 0 judged, 1 not judged', $compact);
        self::assertStringNotContainsString('The fixture value is absent.', $compact);
        $verbose = $this->formatter->format($report, (new FormatterContext(useColor: false, verbose: true))->withDetail(true))->body;
        self::assertStringContainsString('fixture.rule / fixture.channel (project), gate published: The fixture value is absent.', $verbose);
        self::assertStringContainsString('examples: project:fixture', $verbose);
    }

    #[Test]
    public function itReturnsHealthAsName(): void
    {
        self::assertSame('health', $this->formatter->getName());
    }

    #[Test]
    public function itReturnsNoneAsDefaultGroupBy(): void
    {
        self::assertSame(GroupBy::None, $this->formatter->getDefaultGroupBy());
    }

    #[Test]
    public function itFormatsWithNoHealthData(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(10)
            ->filesSkipped(0)
            ->duration(0.5)
            ->build();

        $context = new FormatterContext(useColor: false);
        $output = $this->formatter->format($report, $context)->body;

        self::assertStringContainsString('Health Report', $output);
        self::assertStringContainsString('No health data available', $output);
        self::assertStringContainsString('computed metrics enabled', $output);
        self::assertStringContainsString(ProductIdentity::pointerText(), $output);
    }

    #[Test]
    public function itNamesSeveralEntryFailureKindsWithoutCallingThemPhpFiles(): void
    {
        $coverage = new ReportCoverage(4, 1, 0, 3, [
            new CoverageFailure('src/link', 'directory-symlink', 'Directory link'),
            new CoverageFailure('src/closed', 'unreadable-directory', 'Cannot list directory'),
            new CoverageFailure('src/pipe', 'not-regular-file', 'FIFO'),
        ]);
        $report = ReportBuilder::create()->filesAnalyzed(1)->filesSkipped(3)->coverage($coverage)->build();

        $output = $this->formatter->format($report, new FormatterContext(useColor: false))->body;

        self::assertStringContainsString(
            'Analysis incomplete: 3 of 4 discovered entries failed (1 directory-symlink, 1 not-regular-file, 1 unreadable-directory); policy results are not authoritative.',
            $output,
        );
        self::assertStringNotContainsString('discovered PHP file(s) failed', $output);
    }

    #[Test]
    public function itCarriesTheDocumentationPointerWhenHealthDataIsPresent(): void
    {
        $report = $this->createReportWithHealthScores([
            'overall' => new HealthScore('overall', 67.4, 'Good', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
        ]);

        $context = new FormatterContext(useColor: false);
        $output = $this->formatter->format($report, $context)->body;

        self::assertStringContainsString(ProductIdentity::pointerText(), $output);
    }

    #[Test]
    public function itFormatsWithAllDimensions(): void
    {
        $report = $this->createReportWithHealthScores([
            'complexity' => new HealthScore('complexity', 72.3, 'Good', 60.0, 40.0, HealthCoverage::notApplicable('fixture: this test is not about coverage'), [
                new DecompositionItem('complexity.ccn.avg', 'Cyclomatic (avg)', 3.2, 'below 4', 'lower_is_better', 'manageable branching'),
            ]),
            'cohesion' => new HealthScore('cohesion', 46.7, 'Poor', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
            'coupling' => new HealthScore('coupling', 81.5, 'Good', 60.0, 40.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
            'maintainability' => new HealthScore('maintainability', 68.9, 'Good', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
            'overall' => new HealthScore('overall', 67.4, 'Good', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
        ]);

        $context = new FormatterContext(useColor: false, terminalWidth: 120);
        $output = $this->formatter->format($report, $context)->body;

        self::assertStringContainsString('Health Report', $output);
        self::assertStringContainsString('Dimension', $output);
        self::assertStringContainsString('Score', $output);
        self::assertStringContainsString('Status', $output);
        self::assertStringContainsString('Thresholds', $output);

        // Check dimensions
        self::assertStringContainsString('Complexity', $output);
        self::assertStringContainsString('72.3%', $output);
        self::assertStringContainsString('Cohesion', $output);
        self::assertStringContainsString('46.7%', $output);
        self::assertStringContainsString('Coupling', $output);
        self::assertStringContainsString('81.5%', $output);
        self::assertStringContainsString('Maintainability', $output);
        self::assertStringContainsString('68.9%', $output);

        // Check overall separator
        self::assertStringContainsString('Overall', $output);
        self::assertStringContainsString('67.4%', $output);

        // Check thresholds
        self::assertStringContainsString('warn < 60', $output);
        self::assertStringContainsString('err < 40', $output);
    }

    #[Test]
    public function itShowsDecompositionInOutput(): void
    {
        $report = $this->createReportWithHealthScores([
            'complexity' => new HealthScore('complexity', 72.3, 'Good', 60.0, 40.0, HealthCoverage::notApplicable('fixture: this test is not about coverage'), [
                new DecompositionItem('complexity.ccn.avg', 'Cyclomatic (avg)', 3.2, 'below 4', 'lower_is_better', 'manageable branching'),
                new DecompositionItem('complexity.cognitive.avg', 'Cognitive (avg)', 4.5, 'below 5', 'lower_is_better', ''),
            ]),
            'overall' => new HealthScore('overall', 72.3, 'Good', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
        ]);

        $context = new FormatterContext(useColor: false, terminalWidth: 120);
        $output = $this->formatter->format($report, $context)->body;

        self::assertStringContainsString('Complexity decomposition:', $output);
        self::assertStringContainsString('Cyclomatic (avg)', $output);
        self::assertStringContainsString('3.2', $output);
        self::assertStringContainsString('target: below 4', $output);
        self::assertStringContainsString('Cognitive (avg)', $output);
        self::assertStringContainsString('4.5', $output);
    }

    #[Test]
    public function itFormatsWithNullScore(): void
    {
        $report = $this->createReportWithHealthScores([
            'typing' => new HealthScore('typing', null, '0 classes analyzed', 80.0, 50.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
            'overall' => new HealthScore('overall', 50.0, 'Fair', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
        ]);

        $context = new FormatterContext(useColor: false, terminalWidth: 120);
        $output = $this->formatter->format($report, $context)->body;

        self::assertStringContainsString('Typing', $output);
        self::assertStringContainsString('N/A', $output);
        self::assertStringContainsString('0 classes analyzed', $output);
    }

    #[Test]
    public function itFormatsWithColorEnabled(): void
    {
        $report = $this->createReportWithHealthScores([
            'complexity' => new HealthScore('complexity', 72.3, 'Good', 60.0, 40.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
            'cohesion' => new HealthScore('cohesion', 35.0, 'Poor', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
            'overall' => new HealthScore('overall', 53.7, 'Fair', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
        ]);

        $context = new FormatterContext(useColor: true, terminalWidth: 120);
        $output = $this->formatter->format($report, $context)->body;

        // Green for good score (complexity > warn threshold 60)
        self::assertStringContainsString("\e[32m", $output);
        // Yellow for warning score (cohesion between err 30 and warn 50)
        self::assertStringContainsString("\e[33m", $output);
    }

    #[Test]
    public function itFormatsWithNoColor(): void
    {
        $report = $this->createReportWithHealthScores([
            'complexity' => new HealthScore('complexity', 72.3, 'Good', 60.0, 40.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
            'overall' => new HealthScore('overall', 72.3, 'Good', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
        ]);

        $context = new FormatterContext(useColor: false, terminalWidth: 120);
        $output = $this->formatter->format($report, $context)->body;

        // No ANSI escape codes
        self::assertStringNotContainsString("\e[", $output);
    }

    #[Test]
    public function itFormatsOnNarrowTerminal(): void
    {
        $report = $this->createReportWithHealthScores([
            'complexity' => new HealthScore('complexity', 72.3, 'Good', 60.0, 40.0, HealthCoverage::notApplicable('fixture: this test is not about coverage'), [
                new DecompositionItem('complexity.ccn.avg', 'Cyclomatic (avg)', 3.2, 'below 4', 'lower_is_better', 'manageable branching'),
            ]),
            'overall' => new HealthScore('overall', 72.3, 'Good', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
        ]);

        $context = new FormatterContext(useColor: false, terminalWidth: 50);
        $output = $this->formatter->format($report, $context)->body;

        // Should still show scores
        self::assertStringContainsString('Complexity', $output);
        self::assertStringContainsString('72.3%', $output);

        // No decomposition in narrow mode
        self::assertStringNotContainsString('decomposition:', $output);

        // No header row / thresholds
        self::assertStringNotContainsString('Thresholds', $output);
    }

    #[Test]
    public function itFormatsWithNamespaceFilter(): void
    {
        $report = $this->createReportWithHealthScores([
            'complexity' => new HealthScore('complexity', 72.3, 'Good', 60.0, 40.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
            'overall' => new HealthScore('overall', 72.3, 'Good', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
        ]);

        $context = new FormatterContext(useColor: false, namespace: \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::subtree('App\\Core'), terminalWidth: 120);
        $output = $this->formatter->format($report, $context)->body;

        self::assertStringContainsString('[namespace: subtree:App\\Core]', $output);
    }

    #[Test]
    public function itFormatsWithClassFilter(): void
    {
        $report = $this->createReportWithHealthScores([
            'complexity' => new HealthScore('complexity', 72.3, 'Good', 60.0, 40.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
            'overall' => new HealthScore('overall', 72.3, 'Good', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
        ]);

        $context = new FormatterContext(useColor: false, class: 'App\\Service\\UserService', terminalWidth: 120);
        $output = $this->formatter->format($report, $context)->body;

        self::assertStringContainsString('[class: App\\Service\\UserService]', $output);
    }

    #[Test]
    public function itFormatsWithErrorScore(): void
    {
        $report = $this->createReportWithHealthScores([
            'cohesion' => new HealthScore('cohesion', 20.0, 'Critical', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
            'overall' => new HealthScore('overall', 20.0, 'Critical', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
        ]);

        $context = new FormatterContext(useColor: true, terminalWidth: 120);
        $output = $this->formatter->format($report, $context)->body;

        // Red for error score (below err threshold)
        self::assertStringContainsString("\e[31m", $output);
    }

    #[Test]
    public function itSkipsEmptyDecompositionSection(): void
    {
        $report = $this->createReportWithHealthScores([
            'complexity' => new HealthScore('complexity', 72.3, 'Good', 60.0, 40.0, HealthCoverage::notApplicable('fixture: this test is not about coverage'), []),
            'overall' => new HealthScore('overall', 72.3, 'Good', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
        ]);

        $context = new FormatterContext(useColor: false, terminalWidth: 120);
        $output = $this->formatter->format($report, $context)->body;

        self::assertStringNotContainsString('decomposition:', $output);
    }

    #[Test]
    public function itShowsFileCountInHeader(): void
    {
        $report = $this->createReportWithHealthScores(
            healthScores: [
                'overall' => new HealthScore('overall', 72.3, 'Good', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
            ],
            filesAnalyzed: 1,
        );

        $context = new FormatterContext(useColor: false, terminalWidth: 120);
        $output = $this->formatter->format($report, $context)->body;

        // Singular "file" for 1 file
        self::assertStringContainsString('1 file analyzed', $output);
        self::assertStringNotContainsString('1 files', $output);
    }

    #[Test]
    public function itShowsContributorsInOutput(): void
    {
        $report = $this->createReportWithHealthScores([
            'complexity' => new HealthScore('complexity', 42.0, 'Poor', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage'), [
                new DecompositionItem('complexity.ccn.avg', 'CCN avg', 8.5, '1-3', 'lower_is_better', ''),
            ], [
                new HealthContributor('HeavyService', 'class:App\\HeavyService', ['complexity.ccn.sum' => 45, 'complexity.cognitive.sum' => 30]),
            ]),
            'cohesion' => new HealthScore('cohesion', 46.7, 'Poor', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage'), [
                new DecompositionItem('cohesion.tcc.avg', 'TCC', 0.35, 'above 0.5', 'higher_is_better', ''),
            ], [
                new HealthContributor('ComputedMetricDefinition', 'class:App\\ComputedMetricDefinition', ['cohesion.tcc' => 0.3, 'cohesion.lcom' => 5]),
                new HealthContributor('FormulaParser', 'class:App\\FormulaParser', ['cohesion.tcc' => 0.42, 'cohesion.lcom' => 3]),
                new HealthContributor('ExpressionValidator', 'class:App\\ExpressionValidator', ['cohesion.tcc' => 0.458, 'cohesion.lcom' => 2]),
            ]),
            'overall' => new HealthScore('overall', 67.4, 'Good', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
        ]);

        $context = new FormatterContext(useColor: false, terminalWidth: 120);
        $output = $this->formatter->format($report, $context)->body;

        self::assertStringContainsString('Worst contributors:', $output);

        // Complexity: aggregation suffixes stripped (ccn.sum → CCN, cognitive.sum → COGNITIVE)
        self::assertStringContainsString('HeavyService', $output);
        self::assertStringContainsString('CCN=45', $output);
        self::assertStringContainsString('COGNITIVE=30', $output);
        self::assertStringNotContainsString('CCN.SUM', $output);

        // Cohesion: keys without suffixes stay as-is
        self::assertStringContainsString('ComputedMetricDefinition', $output);
        self::assertStringContainsString('TCC=0.3', $output);
        self::assertStringContainsString('LCOM=5', $output);
        self::assertStringContainsString('FormulaParser', $output);
        self::assertStringContainsString('ExpressionValidator', $output);
    }

    #[Test]
    public function itHidesContributorsWhenContributorsOptionIsZero(): void
    {
        $report = $this->createReportWithHealthScores([
            'cohesion' => new HealthScore('cohesion', 46.7, 'Poor', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage'), [
                new DecompositionItem('cohesion.tcc.avg', 'TCC', 0.35, 'above 0.5', 'higher_is_better', ''),
            ], [
                new HealthContributor('SomeClass', 'class:App\\SomeClass', ['cohesion.tcc' => 0.1]),
            ]),
            'overall' => new HealthScore('overall', 67.4, 'Good', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
        ]);

        $context = new FormatterContext(useColor: false, terminalWidth: 120, options: ['contributors' => '0']);
        $output = $this->formatter->format($report, $context)->body;

        self::assertStringNotContainsString('Worst contributors:', $output);
        self::assertStringNotContainsString('SomeClass', $output);
    }

    #[Test]
    public function itLimitsContributorsByFormatOption(): void
    {
        $report = $this->createReportWithHealthScores([
            'cohesion' => new HealthScore('cohesion', 46.7, 'Poor', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage'), [
                new DecompositionItem('cohesion.tcc.avg', 'TCC', 0.35, 'above 0.5', 'higher_is_better', ''),
            ], [
                new HealthContributor('ClassA', 'class:App\\ClassA', ['cohesion.tcc' => 0.1]),
                new HealthContributor('ClassB', 'class:App\\ClassB', ['cohesion.tcc' => 0.2]),
                new HealthContributor('ClassC', 'class:App\\ClassC', ['cohesion.tcc' => 0.3]),
            ]),
            'overall' => new HealthScore('overall', 67.4, 'Good', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
        ]);

        $context = new FormatterContext(useColor: false, terminalWidth: 120, options: ['contributors' => '1']);
        $output = $this->formatter->format($report, $context)->body;

        self::assertStringContainsString('ClassA', $output);
        self::assertStringNotContainsString('ClassB', $output);
        self::assertStringNotContainsString('ClassC', $output);
    }

    #[Test]
    public function itDoesNotShowContributorsInNarrowTerminal(): void
    {
        $report = $this->createReportWithHealthScores([
            'cohesion' => new HealthScore('cohesion', 46.7, 'Poor', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage'), [
                new DecompositionItem('cohesion.tcc.avg', 'TCC', 0.35, 'above 0.5', 'higher_is_better', ''),
            ], [
                new HealthContributor('SomeClass', 'class:App\\SomeClass', ['cohesion.tcc' => 0.1]),
            ]),
            'overall' => new HealthScore('overall', 67.4, 'Good', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
        ]);

        $context = new FormatterContext(useColor: false, terminalWidth: 50);
        $output = $this->formatter->format($report, $context)->body;

        self::assertStringNotContainsString('Worst contributors:', $output);
    }

    #[Test]
    public function itShowsNoContributorsSectionWhenEmpty(): void
    {
        $report = $this->createReportWithHealthScores([
            'cohesion' => new HealthScore('cohesion', 80.0, 'Good', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage'), [
                new DecompositionItem('cohesion.tcc.avg', 'TCC', 0.8, 'above 0.5', 'higher_is_better', ''),
            ], []),
            'overall' => new HealthScore('overall', 80.0, 'Good', 50.0, 30.0, HealthCoverage::notApplicable('fixture: this test is not about coverage')),
        ]);

        $context = new FormatterContext(useColor: false, terminalWidth: 120);
        $output = $this->formatter->format($report, $context)->body;

        self::assertStringNotContainsString('Worst contributors:', $output);
    }

    /**
     * Coverage used to reach this format only through the decomposition block:
     * wide terminals only, only for dimensions with a decomposition or a worst
     * list, and never for `overall`. It is a column now, so every score that is
     * printed says what share of the subject it speaks for.
     */
    #[Test]
    #[DataProvider('provideTerminalWidths')]
    public function itPrintsCoverageBesideEveryScore(int $terminalWidth): void
    {
        $report = $this->createReportWithHealthScores([
            'cohesion' => new HealthScore('cohesion', 82.9, 'Excellent', 60.0, 30.0, HealthCoverage::over(3, 12, CoverageUnit::Classes, 'cohesion.tcc.count')),
            'overall' => new HealthScore('overall', 75.3, 'Acceptable', 50.0, 30.0, HealthCoverage::notApplicable('composes the other dimensions')),
        ]);

        $output = $this->formatter->format($report, new FormatterContext(useColor: false, terminalWidth: $terminalWidth))->body;

        self::assertStringContainsString('25%', $output);
        self::assertStringContainsString('n/a', $output);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideTerminalWidths(): iterable
    {
        yield 'wide' => [120];
        yield 'narrow' => [50];
    }

    #[Test]
    public function itNamesFindingsAndTheWholeRunOutsideACleanScopeEvenWithoutScores(): void
    {
        $report = ReportBuilder::create()->filesAnalyzed(1)
            ->outOfScope(new \Qualimetrix\Reporting\DrillDown\OutOfScopeFindings(1, 2, 3))->build();
        $output = $this->formatter->format($report, new FormatterContext(useColor: false))->body;
        self::assertStringContainsString('Findings: 0 error(s), 0 warning(s), 0 info', $output);
        self::assertStringContainsString('Outside this scope: 1 error(s), 2 warning(s), 3 info', $output);
    }

    /** @param array<string, HealthScore> $healthScores */
    private function createReportWithHealthScores(
        array $healthScores,
        int $filesAnalyzed = 10,
    ): Report {
        return new Report(
            findings: [],
            filesAnalyzed: $filesAnalyzed,
            filesSkipped: 0,
            duration: 0.5,
            errorCount: 0,
            warningCount: 0,
            healthScores: $healthScores,
        );
    }

    #[Test]
    public function itShowsBothNonfailureAbsencesBeforeReturningWithoutScores(): void
    {
        $summary = new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluationSummary([
            new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricValueAbsence(
                'computed.custom',
                \Qualimetrix\Core\Symbol\SymbolLevel::Project,
                2,
                1,
                ['missing.input'],
                [\Qualimetrix\Core\Symbol\MetricSubject::aggregate(\Qualimetrix\Core\Symbol\SymbolPath::forProject())],
            ),
        ]);
        $report = new Report([], 1, 0, 0.0, 0, 0, computedMetricEvaluation: $summary);
        $body = $this->formatter->format($report, new FormatterContext(useColor: false))->body;
        self::assertStringContainsString('Computed metric computed.custom (project): not measured', $body);
        self::assertStringContainsString('missing keys [missing.input] for 2 subject(s)', $body);
        self::assertStringContainsString('no value for 1 subject(s)', $body);
        $withScores = new \Qualimetrix\Reporting\Report([], 1, 0, 0.0, 0, 0, healthScores: [
            'overall' => new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthScore('overall', 0.0, 'Critical', 50.0, 25.0, \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthCoverage::notApplicable('composes dimensions')),
        ], computedMetricEvaluation: $summary);
        $alongside = $this->formatter->format($withScores, new FormatterContext(useColor: false))->body;
        self::assertStringContainsString('missing keys [missing.input] for 2 subject(s)', $alongside);
        self::assertStringContainsString('no value for 1 subject(s)', $alongside);
        $expected = 'Computed metric computed.custom (project): not measured — missing keys [missing.input] for 2 subject(s); no value for 1 subject(s); examples: project:';
        self::assertContains($expected, explode("\n", $body));
        self::assertContains($expected, explode("\n", $alongside));
        self::assertSame(1, substr_count($body, 'Computed metric computed.custom'));
        self::assertSame(1, substr_count($alongside, 'Computed metric computed.custom'));

    }

    #[Test]
    public function itShowsActualCustomAbsencesWithNullLoggerBeforeAndBesideHealth(): void
    {
        $repository = new \Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository([
            new \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition('cohesion.tcc', \Qualimetrix\Core\Symbol\SymbolLevel::Class_),
        ]);
        foreach (['One' => [], 'Two' => [], 'Pair' => ['cohesion.tcc' => 1]] as $name => $metrics) {
            $file = \Qualimetrix\Core\Path\RelativePath::fromString('src/' . $name . '.php');
            $subject = \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of(
                \Qualimetrix\Core\Symbol\SymbolPath::forClass('App', $name),
                $file,
                \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0),
            ));
            $repository->addSubject($subject, \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag::fromArray($metrics), $file, 1);
        }
        $analysis = new \Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricAnalysis(
            new \Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricsConfigResolver(
                new \Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricFormulaValidator(),
                new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration\HealthFormulaExcluder(),
            ),
        );
        $analysis->replace(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions([
            new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition(
                'computed.custom',
                ['class' => 'm["cohesion.tcc"] > 0 ? null : m["coupling.cbo"]'],
                'Custom',
                [\Qualimetrix\Core\Symbol\SymbolLevel::Class_],
            ),
        ]));
        $summary = (new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluator(
            $analysis,
            self::createStub(\Qualimetrix\Core\Profiler\Contract\ProfilerInterface::class),
            new \Psr\Log\NullLogger(),
        ))->evaluate($repository, 1);
        self::assertCount(1, $summary->absences);
        self::assertSame(2, $summary->absences[0]->missingKeysCount);
        self::assertSame(1, $summary->absences[0]->noValueCount);
        foreach ([[], ['overall' => new HealthScore('overall', 80.0, 'Good', 50.0, 25.0, HealthCoverage::notApplicable('composes dimensions'))]] as $scores) {
            $body = $this->formatter->format(new Report([], 1, 0, 0.0, 0, 0, healthScores: $scores, computedMetricEvaluation: $summary), new FormatterContext(useColor: false))->body;
            self::assertSame(1, substr_count($body, 'Computed metric computed.custom (class):'));
            self::assertStringContainsString('missing keys [cohesion.tcc] for 2 subject(s)', $body);
            self::assertStringContainsString('no value for 1 subject(s)', $body);
        }
    }

}
