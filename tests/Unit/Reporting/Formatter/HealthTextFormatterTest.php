<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Reporting\Formatter\Health\HealthTextFormatter;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Health\DecompositionItem;
use Qualimetrix\Reporting\Health\HealthContributor;
use Qualimetrix\Reporting\Health\HealthScore;
use Qualimetrix\Reporting\Health\HealthScoreResolver;
use Qualimetrix\Reporting\Health\MetricHintProvider;
use Qualimetrix\Reporting\Health\NamespaceDrillDown;
use Qualimetrix\Reporting\Report;
use Qualimetrix\Reporting\ReportBuilder;

#[CoversClass(HealthTextFormatter::class)]
final class HealthTextFormatterTest extends TestCase
{
    private HealthTextFormatter $formatter;

    protected function setUp(): void
    {
        $hintProvider = new MetricHintProvider();
        $drillDown = new NamespaceDrillDown($hintProvider);
        $resolver = new HealthScoreResolver($drillDown);
        $this->formatter = new HealthTextFormatter($resolver);
    }

    public function testGetNameReturnsHealth(): void
    {
        self::assertSame('health', $this->formatter->getName());
    }

    public function testGetDefaultGroupByReturnsNone(): void
    {
        self::assertSame(GroupBy::None, $this->formatter->getDefaultGroupBy());
    }

    public function testFormatWithNoHealthData(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(10)
            ->filesSkipped(0)
            ->duration(0.5)
            ->build();

        $context = new FormatterContext(useColor: false);
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('Health Report', $output);
        self::assertStringContainsString('No health data available', $output);
        self::assertStringContainsString('computed metrics enabled', $output);
    }

    public function testFormatWithAllDimensions(): void
    {
        $report = $this->createReportWithHealthScores([
            'complexity' => new HealthScore('complexity', 72.3, 'Good', 60.0, 40.0, [
                new DecompositionItem('ccn.avg', 'Cyclomatic (avg)', 3.2, 'below 4', 'lower_is_better', 'manageable branching'),
            ]),
            'cohesion' => new HealthScore('cohesion', 46.7, 'Poor', 50.0, 30.0),
            'coupling' => new HealthScore('coupling', 81.5, 'Good', 60.0, 40.0),
            'maintainability' => new HealthScore('maintainability', 68.9, 'Good', 50.0, 30.0),
            'overall' => new HealthScore('overall', 67.4, 'Good', 50.0, 30.0),
        ]);

        $context = new FormatterContext(useColor: false, terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

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

    public function testFormatShowsDecomposition(): void
    {
        $report = $this->createReportWithHealthScores([
            'complexity' => new HealthScore('complexity', 72.3, 'Good', 60.0, 40.0, [
                new DecompositionItem('ccn.avg', 'Cyclomatic (avg)', 3.2, 'below 4', 'lower_is_better', 'manageable branching'),
                new DecompositionItem('cognitive.avg', 'Cognitive (avg)', 4.5, 'below 5', 'lower_is_better', ''),
            ]),
            'overall' => new HealthScore('overall', 72.3, 'Good', 50.0, 30.0),
        ]);

        $context = new FormatterContext(useColor: false, terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('Complexity decomposition:', $output);
        self::assertStringContainsString('Cyclomatic (avg)', $output);
        self::assertStringContainsString('3.2', $output);
        self::assertStringContainsString('target: below 4', $output);
        self::assertStringContainsString('Cognitive (avg)', $output);
        self::assertStringContainsString('4.5', $output);
    }

    public function testFormatWithNullScore(): void
    {
        $report = $this->createReportWithHealthScores([
            'typing' => new HealthScore('typing', null, '0 classes analyzed', 80.0, 50.0),
            'overall' => new HealthScore('overall', 50.0, 'Fair', 50.0, 30.0),
        ]);

        $context = new FormatterContext(useColor: false, terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('Typing', $output);
        self::assertStringContainsString('N/A', $output);
        self::assertStringContainsString('0 classes analyzed', $output);
    }

    public function testFormatWithColorEnabled(): void
    {
        $report = $this->createReportWithHealthScores([
            'complexity' => new HealthScore('complexity', 72.3, 'Good', 60.0, 40.0),
            'cohesion' => new HealthScore('cohesion', 35.0, 'Poor', 50.0, 30.0),
            'overall' => new HealthScore('overall', 53.7, 'Fair', 50.0, 30.0),
        ]);

        $context = new FormatterContext(useColor: true, terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        // Green for good score (complexity > warn threshold 60)
        self::assertStringContainsString("\e[32m", $output);
        // Yellow for warning score (cohesion between err 30 and warn 50)
        self::assertStringContainsString("\e[33m", $output);
    }

    public function testFormatWithNoColor(): void
    {
        $report = $this->createReportWithHealthScores([
            'complexity' => new HealthScore('complexity', 72.3, 'Good', 60.0, 40.0),
            'overall' => new HealthScore('overall', 72.3, 'Good', 50.0, 30.0),
        ]);

        $context = new FormatterContext(useColor: false, terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        // No ANSI escape codes
        self::assertStringNotContainsString("\e[", $output);
    }

    public function testFormatNarrowTerminal(): void
    {
        $report = $this->createReportWithHealthScores([
            'complexity' => new HealthScore('complexity', 72.3, 'Good', 60.0, 40.0, [
                new DecompositionItem('ccn.avg', 'Cyclomatic (avg)', 3.2, 'below 4', 'lower_is_better', 'manageable branching'),
            ]),
            'overall' => new HealthScore('overall', 72.3, 'Good', 50.0, 30.0),
        ]);

        $context = new FormatterContext(useColor: false, terminalWidth: 50);
        $output = $this->formatter->format($report, $context);

        // Should still show scores
        self::assertStringContainsString('Complexity', $output);
        self::assertStringContainsString('72.3%', $output);

        // No decomposition in narrow mode
        self::assertStringNotContainsString('decomposition:', $output);

        // No header row / thresholds
        self::assertStringNotContainsString('Thresholds', $output);
    }

    public function testFormatWithNamespaceFilter(): void
    {
        $report = $this->createReportWithHealthScores([
            'complexity' => new HealthScore('complexity', 72.3, 'Good', 60.0, 40.0),
            'overall' => new HealthScore('overall', 72.3, 'Good', 50.0, 30.0),
        ]);

        $context = new FormatterContext(useColor: false, namespace: 'App\\Core', terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('[namespace: App\\Core]', $output);
    }

    public function testFormatWithClassFilter(): void
    {
        $report = $this->createReportWithHealthScores([
            'complexity' => new HealthScore('complexity', 72.3, 'Good', 60.0, 40.0),
            'overall' => new HealthScore('overall', 72.3, 'Good', 50.0, 30.0),
        ]);

        $context = new FormatterContext(useColor: false, class: 'App\\Service\\UserService', terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('[class: App\\Service\\UserService]', $output);
    }

    public function testFormatWithErrorScore(): void
    {
        $report = $this->createReportWithHealthScores([
            'cohesion' => new HealthScore('cohesion', 20.0, 'Critical', 50.0, 30.0),
            'overall' => new HealthScore('overall', 20.0, 'Critical', 50.0, 30.0),
        ]);

        $context = new FormatterContext(useColor: true, terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        // Red for error score (below err threshold)
        self::assertStringContainsString("\e[31m", $output);
    }

    public function testFormatEmptyDecompositionSkipped(): void
    {
        $report = $this->createReportWithHealthScores([
            'complexity' => new HealthScore('complexity', 72.3, 'Good', 60.0, 40.0, []),
            'overall' => new HealthScore('overall', 72.3, 'Good', 50.0, 30.0),
        ]);

        $context = new FormatterContext(useColor: false, terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        self::assertStringNotContainsString('decomposition:', $output);
    }

    public function testFileCountInHeader(): void
    {
        $report = $this->createReportWithHealthScores(
            healthScores: [
                'overall' => new HealthScore('overall', 72.3, 'Good', 50.0, 30.0),
            ],
            filesAnalyzed: 1,
        );

        $context = new FormatterContext(useColor: false, terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        // Singular "file" for 1 file
        self::assertStringContainsString('1 file analyzed', $output);
        self::assertStringNotContainsString('1 files', $output);
    }

    public function testFormatShowsContributors(): void
    {
        $report = $this->createReportWithHealthScores([
            'cohesion' => new HealthScore('cohesion', 46.7, 'Poor', 50.0, 30.0, [
                new DecompositionItem('tcc.avg', 'TCC', 0.35, 'above 0.5', 'higher_is_better', ''),
            ], [
                new HealthContributor('ComputedMetricDefinition', 'class:App\\ComputedMetricDefinition', ['tcc' => 0.3, 'lcom' => 5]),
                new HealthContributor('FormulaParser', 'class:App\\FormulaParser', ['tcc' => 0.42, 'lcom' => 3]),
                new HealthContributor('ExpressionValidator', 'class:App\\ExpressionValidator', ['tcc' => 0.458, 'lcom' => 2]),
            ]),
            'overall' => new HealthScore('overall', 67.4, 'Good', 50.0, 30.0),
        ]);

        $context = new FormatterContext(useColor: false, terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('Worst contributors:', $output);
        self::assertStringContainsString('ComputedMetricDefinition', $output);
        self::assertStringContainsString('TCC=0.3', $output);
        self::assertStringContainsString('LCOM=5', $output);
        self::assertStringContainsString('FormulaParser', $output);
        self::assertStringContainsString('ExpressionValidator', $output);
    }

    public function testContributorsHiddenWhenContributorsZero(): void
    {
        $report = $this->createReportWithHealthScores([
            'cohesion' => new HealthScore('cohesion', 46.7, 'Poor', 50.0, 30.0, [
                new DecompositionItem('tcc.avg', 'TCC', 0.35, 'above 0.5', 'higher_is_better', ''),
            ], [
                new HealthContributor('SomeClass', 'class:App\\SomeClass', ['tcc' => 0.1]),
            ]),
            'overall' => new HealthScore('overall', 67.4, 'Good', 50.0, 30.0),
        ]);

        $context = new FormatterContext(useColor: false, terminalWidth: 120, options: ['contributors' => '0']);
        $output = $this->formatter->format($report, $context);

        self::assertStringNotContainsString('Worst contributors:', $output);
        self::assertStringNotContainsString('SomeClass', $output);
    }

    public function testContributorsLimitedByFormatOption(): void
    {
        $report = $this->createReportWithHealthScores([
            'cohesion' => new HealthScore('cohesion', 46.7, 'Poor', 50.0, 30.0, [
                new DecompositionItem('tcc.avg', 'TCC', 0.35, 'above 0.5', 'higher_is_better', ''),
            ], [
                new HealthContributor('ClassA', 'class:App\\ClassA', ['tcc' => 0.1]),
                new HealthContributor('ClassB', 'class:App\\ClassB', ['tcc' => 0.2]),
                new HealthContributor('ClassC', 'class:App\\ClassC', ['tcc' => 0.3]),
            ]),
            'overall' => new HealthScore('overall', 67.4, 'Good', 50.0, 30.0),
        ]);

        $context = new FormatterContext(useColor: false, terminalWidth: 120, options: ['contributors' => '1']);
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('ClassA', $output);
        self::assertStringNotContainsString('ClassB', $output);
        self::assertStringNotContainsString('ClassC', $output);
    }

    public function testContributorsNotShownInNarrowTerminal(): void
    {
        $report = $this->createReportWithHealthScores([
            'cohesion' => new HealthScore('cohesion', 46.7, 'Poor', 50.0, 30.0, [
                new DecompositionItem('tcc.avg', 'TCC', 0.35, 'above 0.5', 'higher_is_better', ''),
            ], [
                new HealthContributor('SomeClass', 'class:App\\SomeClass', ['tcc' => 0.1]),
            ]),
            'overall' => new HealthScore('overall', 67.4, 'Good', 50.0, 30.0),
        ]);

        $context = new FormatterContext(useColor: false, terminalWidth: 50);
        $output = $this->formatter->format($report, $context);

        self::assertStringNotContainsString('Worst contributors:', $output);
    }

    public function testContributorsEmptyShowsNoSection(): void
    {
        $report = $this->createReportWithHealthScores([
            'cohesion' => new HealthScore('cohesion', 80.0, 'Good', 50.0, 30.0, [
                new DecompositionItem('tcc.avg', 'TCC', 0.8, 'above 0.5', 'higher_is_better', ''),
            ], []),
            'overall' => new HealthScore('overall', 80.0, 'Good', 50.0, 30.0),
        ]);

        $context = new FormatterContext(useColor: false, terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        self::assertStringNotContainsString('Worst contributors:', $output);
    }

    /**
     * @param array<string, HealthScore> $healthScores
     */
    private function createReportWithHealthScores(
        array $healthScores,
        int $filesAnalyzed = 10,
    ): Report {
        return new Report(
            violations: [],
            filesAnalyzed: $filesAnalyzed,
            filesSkipped: 0,
            duration: 0.5,
            errorCount: 0,
            warningCount: 0,
            healthScores: $healthScores,
        );
    }
}
