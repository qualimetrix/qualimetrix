<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Summary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Filter\ViolationFilter;
use Qualimetrix\Reporting\Formatter\Summary\HintRenderer;
use Qualimetrix\Reporting\Formatter\Summary\OffenderListRenderer;
use Qualimetrix\Reporting\Formatter\Support\AnsiColor;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Health\HealthScore;
use Qualimetrix\Reporting\Health\MetricHintProvider;
use Qualimetrix\Reporting\Health\NamespaceDrillDown;
use Qualimetrix\Reporting\Health\WorstOffender;
use Qualimetrix\Reporting\Report;

#[CoversClass(HintRenderer::class)]
final class HintRendererTest extends TestCase
{
    private HintRenderer $renderer;
    private AnsiColor $color;

    protected function setUp(): void
    {
        $namespaceDrillDown = new NamespaceDrillDown(new MetricHintProvider());
        $offenderList = new OffenderListRenderer(new ViolationFilter(), $namespaceDrillDown);
        $this->renderer = new HintRenderer($offenderList);
        $this->color = new AnsiColor(false);
    }

    public function testHintDetailShownWhenNotInDetailMode(): void
    {
        // Report must be non-empty (has violations) for --detail hint to appear
        $violation = new Violation(
            location: new Location('/src/Service.php', 10),
            symbolPath: SymbolPath::forClass('App\\Service', 'Service'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Test',
            severity: Severity::Error,
        );

        $report = new Report(
            violations: [$violation],
            filesAnalyzed: 1,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 1,
            warningCount: 0,
        );

        $context = new FormatterContext();
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('--detail to see violations', $output);
    }

    public function testHintDetailHiddenInDetailMode(): void
    {
        $violation = new Violation(
            location: new Location('/src/Service.php', 10),
            symbolPath: SymbolPath::forClass('App\\Service', 'Service'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Test',
            severity: Severity::Error,
        );

        $report = new Report(
            violations: [$violation],
            filesAnalyzed: 1,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 1,
            warningCount: 0,
        );

        $context = new FormatterContext(detailLimit: 200);
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = implode("\n", $lines);
        self::assertStringNotContainsString('--detail', $output);
    }

    public function testHintScopedReporting(): void
    {
        $report = $this->createNonEmptyReport();
        $context = new FormatterContext(scopedReporting: true);
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('scoped analysis', $output);
        self::assertStringContainsString('violations filtered to changed files only', $output);
    }

    public function testHintProjectLevelDrillDown(): void
    {
        $worstNs = new WorstOffender(
            symbolPath: SymbolPath::forNamespace('App\\Service'),
            file: null,
            healthOverall: 40.0,
            label: 'Poor',
            reason: 'Many violations',
            violationCount: 10,
            classCount: 5,
        );

        $report = new Report(
            violations: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            healthScores: [
                'overall' => new HealthScore('overall', 60.0, 'Needs work', 60.0, 30.0),
            ],
            worstNamespaces: [$worstNs],
        );

        $context = new FormatterContext();
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString("--namespace='App\\Service'", $output);
        self::assertStringContainsString('to drill down', $output);
    }

    public function testHintAlwaysShowsHtmlFormat(): void
    {
        $report = $this->createNonEmptyReport();
        $context = new FormatterContext(detailLimit: 200);
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('--format=html -o report.html', $output);
    }

    public function testHintEscapesBackslashesInNamespace(): void
    {
        $worstNs = new WorstOffender(
            symbolPath: SymbolPath::forNamespace('App\\Service\\Payment'),
            file: null,
            healthOverall: 40.0,
            label: 'Poor',
            reason: 'Issues',
            violationCount: 5,
            classCount: 3,
        );

        $report = new Report(
            violations: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            healthScores: [
                'overall' => new HealthScore('overall', 60.0, 'Needs work', 60.0, 30.0),
            ],
            worstNamespaces: [$worstNs],
        );

        $context = new FormatterContext();
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = implode("\n", $lines);
        // Namespace with backslashes should be single-quoted for shell safety
        self::assertStringContainsString("'App\\Service\\Payment'", $output);
    }

    public function testHintNoHealthScoresSkipsDrillDown(): void
    {
        $report = new Report(
            violations: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            healthScores: [],
        );

        $context = new FormatterContext();
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = implode("\n", $lines);
        self::assertStringNotContainsString('--namespace=', $output);
        self::assertStringNotContainsString('--class=', $output);
    }

    public function testHintClassScopeSkipsDrillDown(): void
    {
        $report = new Report(
            violations: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            healthScores: [
                'overall' => new HealthScore('overall', 60.0, 'Needs work', 60.0, 30.0),
            ],
        );

        // Class-level context — no deeper drill-down possible
        $context = new FormatterContext(class: 'App\\Service\\UserService');
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = implode("\n", $lines);
        self::assertStringNotContainsString('--namespace=', $output);
        self::assertStringNotContainsString('--class=', $output);
    }

    public function testHintsPipeSeparated(): void
    {
        $report = $this->createNonEmptyReport();
        $context = new FormatterContext(scopedReporting: true);
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = $lines[0];
        // Hints should be separated by ' | '
        self::assertStringContainsString(' | ', $output);
        self::assertStringStartsWith('Hints: ', $output);
    }

    public function testHintDetailHiddenWhenReportIsEmpty(): void
    {
        // isEmpty() returns true when violations === []
        $report = new Report(
            violations: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
        );

        // isEmpty() is true AND detail is not enabled => hint shown
        // BUT !report->isEmpty() is false, so detail hint is skipped
        $context = new FormatterContext();
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = implode("\n", $lines);
        // Report is empty (no violations), so --detail hint should be shown
        // Actually: !$report->isEmpty() means violations !== [], so for empty report detail hint is hidden
        self::assertStringNotContainsString('--detail', $output);
    }

    private function createNonEmptyReport(): Report
    {
        return new Report(
            violations: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
        );
    }
}
