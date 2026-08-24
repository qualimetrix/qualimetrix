<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Summary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\DrillDown\WorstClassDrillDown;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthScore;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\WorstOffenderEvidence;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Filter\FindingFilter;
use Qualimetrix\Reporting\Formatter\Summary\HintRenderer;
use Qualimetrix\Reporting\Formatter\Summary\OffenderListRenderer;
use Qualimetrix\Reporting\Formatter\Support\AnsiColor;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Report;

#[CoversClass(HintRenderer::class)]
final class HintRendererTest extends TestCase
{
    private HintRenderer $renderer;
    private AnsiColor $color;

    protected function setUp(): void
    {
        $definitionCatalog = self::createStub(ComputedMetricDefinitionCatalogInterface::class);
        $offenderList = new OffenderListRenderer(new FindingFilter(), new WorstClassDrillDown($definitionCatalog));
        $this->renderer = new HintRenderer($offenderList);
        $this->color = new AnsiColor(false);
    }

    #[Test]
    public function itShowsHintDetailWhenNotInDetailMode(): void
    {
        // Report must be non-empty (has findings) for --detail hint to appear
        $finding = new Finding(
            location: new Location(RelativePath::fromString('src/Service.php'), 10),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('App\\Service', 'Service'), RelativePath::fromString('src/Service.php'), DeclarationOrdinal::fromRank(0))),
            symbolPath: SymbolPath::forClass('App\\Service', 'Service'),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic',
            message: 'Test',
            severity: Severity::Error,
        );

        $report = new Report(
            findings: [$finding],
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

    #[Test]
    public function itHidesHintDetailInDetailMode(): void
    {
        $finding = new Finding(
            location: new Location(RelativePath::fromString('src/Service.php'), 10),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('App\\Service', 'Service'), RelativePath::fromString('src/Service.php'), DeclarationOrdinal::fromRank(0))),
            symbolPath: SymbolPath::forClass('App\\Service', 'Service'),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic',
            message: 'Test',
            severity: Severity::Error,
        );

        $report = new Report(
            findings: [$finding],
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

    #[Test]
    public function itShowsHintForScopedReporting(): void
    {
        $report = $this->createNonEmptyReport();
        $context = new FormatterContext(scopedReporting: true);
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('scoped analysis', $output);
        self::assertStringContainsString('violations filtered to changed files only', $output);
    }

    #[Test]
    public function itShowsProjectLevelDrillDownHint(): void
    {
        $worstNs = new WorstOffender(
            symbolPath: SymbolPath::forNamespace('App\\Service'),
            file: null,
            healthOverall: 40.0,
            label: 'Poor',
            reason: 'Many violations',
            evidence: new WorstOffenderEvidence(
                violationCount: 10,
                classCount: 5,
            ),
        );

        $report = new Report(
            findings: [],
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

    #[Test]
    public function itShowsClassDrillDownHintInNamespaceScope(): void
    {
        $worstClass = new WorstOffender(
            symbolPath: SymbolPath::forClass('App\\Service', 'PaymentService'),
            file: null,
            healthOverall: 35.0,
            label: 'Poor',
            reason: 'Low cohesion',
            evidence: new WorstOffenderEvidence(
                violationCount: 4,
                classCount: 0,
            ),
        );

        $report = new Report(
            findings: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            healthScores: [
                'overall' => new HealthScore('overall', 60.0, 'Needs work', 60.0, 30.0),
            ],
            worstClasses: [$worstClass],
        );

        // Namespace-level context — drill-down hint should suggest --class, not --namespace
        $context = new FormatterContext(namespace: 'App\\Service');
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString("--class='App\\Service\\PaymentService'", $output);
        self::assertStringContainsString('to drill deeper', $output);
        self::assertStringNotContainsString('--namespace=', $output);
    }

    #[Test]
    public function itAlwaysShowsHtmlFormatHint(): void
    {
        $report = $this->createNonEmptyReport();
        $context = new FormatterContext(detailLimit: 200);
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('--format=html -o report.html', $output);
    }

    #[Test]
    public function itEscapesBackslashesInNamespaceHint(): void
    {
        $worstNs = new WorstOffender(
            symbolPath: SymbolPath::forNamespace('App\\Service\\Payment'),
            file: null,
            healthOverall: 40.0,
            label: 'Poor',
            reason: 'Issues',
            evidence: new WorstOffenderEvidence(
                violationCount: 5,
                classCount: 3,
            ),
        );

        $report = new Report(
            findings: [],
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

    #[Test]
    public function itSkipsDrillDownHintWhenNoHealthScores(): void
    {
        $report = new Report(
            findings: [],
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

    #[Test]
    public function itSkipsDrillDownHintInClassScope(): void
    {
        $report = new Report(
            findings: [],
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

    #[Test]
    public function itSeparatesHintsWithPipe(): void
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

    #[Test]
    public function itHidesHintDetailWhenReportIsEmpty(): void
    {
        // isEmpty() returns true when findings === []
        $report = new Report(
            findings: [],
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
        // Report is empty (no findings), so --detail hint should be shown
        // Actually: !$report->isEmpty() means findings !== [], so for empty report detail hint is hidden
        self::assertStringNotContainsString('--detail', $output);
    }

    private function createNonEmptyReport(): Report
    {
        return new Report(
            findings: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
        );
    }
}
