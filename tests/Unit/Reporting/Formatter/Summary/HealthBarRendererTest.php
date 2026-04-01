<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Summary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Reporting\Formatter\Summary\HealthBarRenderer;
use Qualimetrix\Reporting\Formatter\Support\AnsiColor;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Health\DecompositionItem;
use Qualimetrix\Reporting\Health\HealthScore;
use Qualimetrix\Reporting\Health\HealthScoreResolver;
use Qualimetrix\Reporting\Health\MetricHintProvider;
use Qualimetrix\Reporting\Health\NamespaceDrillDown;
use Qualimetrix\Reporting\Report;

#[CoversClass(HealthBarRenderer::class)]
final class HealthBarRendererTest extends TestCase
{
    private HealthBarRenderer $renderer;
    private AnsiColor $color;

    protected function setUp(): void
    {
        $resolver = new HealthScoreResolver(new NamespaceDrillDown(new MetricHintProvider()));
        $this->renderer = new HealthBarRenderer($resolver);
        $this->color = new AnsiColor(false);
    }

    public function testRenderShowsInsufficientDataWhenNoScores(): void
    {
        $report = $this->createReport();
        $lines = [];

        $this->renderer->render($report, new FormatterContext(), $this->color, 80, false, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('Health: insufficient data', $output);
    }

    public function testRenderOverallAndDimensions(): void
    {
        $report = $this->createReport(healthScores: [
            'overall' => new HealthScore('overall', 75.3, 'Acceptable', 60.0, 30.0),
            'complexity' => new HealthScore('complexity', 82.0, 'Good', 60.0, 30.0),
            'coupling' => new HealthScore('coupling', 45.5, 'Needs work', 60.0, 30.0),
        ]);
        $lines = [];

        $this->renderer->render($report, new FormatterContext(), $this->color, 80, false, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('Health', $output);
        self::assertStringContainsString('75.3%', $output);
        self::assertStringContainsString('Acceptable', $output);
        self::assertStringContainsString('Complexity', $output);
        self::assertStringContainsString('82%', $output);
        self::assertStringContainsString('Coupling', $output);
        self::assertStringContainsString('45.5%', $output);
    }

    public function testRenderBoundaryScoreZero(): void
    {
        $report = $this->createReport(healthScores: [
            'overall' => new HealthScore('overall', 0.0, 'Critical', 60.0, 30.0),
        ]);
        $lines = [];

        $this->renderer->render($report, new FormatterContext(), $this->color, 80, false, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('0%', $output);
        self::assertStringContainsString('Critical', $output);
    }

    public function testRenderBoundaryScoreHundred(): void
    {
        $report = $this->createReport(healthScores: [
            'overall' => new HealthScore('overall', 100.0, 'Excellent', 60.0, 30.0),
        ]);
        $lines = [];

        $this->renderer->render($report, new FormatterContext(), $this->color, 80, false, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('100%', $output);
    }

    public function testRenderNanScoreHandled(): void
    {
        $report = $this->createReport(healthScores: [
            'overall' => new HealthScore('overall', \NAN, 'N/A', 60.0, 30.0),
        ]);
        $lines = [];

        $this->renderer->render($report, new FormatterContext(), $this->color, 80, false, $lines);

        $output = implode("\n", $lines);
        // NaN formatted as dash
        self::assertStringContainsString('—%', $output);
    }

    public function testRenderAsciiBar(): void
    {
        $report = $this->createReport(healthScores: [
            'overall' => new HealthScore('overall', 50.0, 'Needs work', 60.0, 30.0),
        ]);
        $lines = [];

        $this->renderer->render($report, new FormatterContext(), $this->color, 80, true, $lines);

        $output = implode("\n", $lines);
        // ASCII bar uses # and . characters enclosed in []
        self::assertStringContainsString('[', $output);
        self::assertStringContainsString(']', $output);
        self::assertStringContainsString('#', $output);
        self::assertStringContainsString('.', $output);
    }

    public function testRenderUnicodeBar(): void
    {
        $report = $this->createReport(healthScores: [
            'overall' => new HealthScore('overall', 50.0, 'Needs work', 60.0, 30.0),
        ]);
        $lines = [];

        $this->renderer->render($report, new FormatterContext(), $this->color, 80, false, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('█', $output);
        self::assertStringContainsString('░', $output);
    }

    public function testRenderNullScoreDimensionShowsNA(): void
    {
        $report = $this->createReport(healthScores: [
            'overall' => new HealthScore('overall', 80.0, 'Good', 60.0, 30.0),
            'typing' => new HealthScore('typing', null, 'No classes analyzed', 80.0, 50.0),
        ]);
        $lines = [];

        $this->renderer->render($report, new FormatterContext(), $this->color, 80, false, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('Typing', $output);
        self::assertStringContainsString('N/A', $output);
    }

    public function testRenderNarrowTerminalSkipsBars(): void
    {
        $report = $this->createReport(healthScores: [
            'overall' => new HealthScore('overall', 70.0, 'Acceptable', 60.0, 30.0),
            'complexity' => new HealthScore('complexity', 85.0, 'Good', 60.0, 30.0),
        ]);
        $lines = [];

        // Terminal width < 80 => no bars for dimensions
        $this->renderer->render($report, new FormatterContext(), $this->color, 60, false, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('Complexity', $output);
        self::assertStringContainsString('85%', $output);
        // Dimension lines should not contain bar characters
        $dimensionLines = array_filter($lines, static fn(string $l): bool => str_contains($l, 'Complexity'));
        foreach ($dimensionLines as $line) {
            self::assertStringNotContainsString('█', $line);
            self::assertStringNotContainsString('░', $line);
        }
    }

    public function testRenderShowsNamespaceHeader(): void
    {
        $report = $this->createReport(healthScores: [
            'overall' => new HealthScore('overall', 60.0, 'Needs work', 60.0, 30.0),
        ]);
        $lines = [];

        $context = new FormatterContext(namespace: 'App\\Service');
        $this->renderer->render($report, $context, $this->color, 80, false, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('[namespace: App\\Service]', $output);
    }

    public function testRenderShowsClassHeader(): void
    {
        $report = $this->createReport(healthScores: [
            'overall' => new HealthScore('overall', 60.0, 'Needs work', 60.0, 30.0),
        ]);
        $lines = [];

        $context = new FormatterContext(class: 'App\\Service\\UserService');
        $this->renderer->render($report, $context, $this->color, 80, false, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('[class: App\\Service\\UserService]', $output);
    }

    public function testRenderDecompositionItems(): void
    {
        $decomposition = [
            new DecompositionItem(
                metricKey: 'avg_ccn',
                humanName: 'Avg CCN',
                value: 12.5,
                goodValue: '≤ 5',
                direction: 'lower',
                explanation: 'Average cyclomatic complexity per method',
            ),
        ];

        $report = $this->createReport(healthScores: [
            'overall' => new HealthScore('overall', 40.0, 'Poor', 60.0, 30.0),
            'complexity' => new HealthScore('complexity', 35.0, 'Poor', 60.0, 30.0, $decomposition),
        ]);
        $lines = [];

        $this->renderer->render($report, new FormatterContext(), $this->color, 80, false, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('↳', $output);
        self::assertStringContainsString('Avg CCN', $output);
        self::assertStringContainsString('12.5', $output);
        self::assertStringContainsString('≤ 5', $output);
        self::assertStringContainsString('Average cyclomatic complexity per method', $output);
    }

    public function testRenderShowsScaleExplanationWhenDifferentThresholds(): void
    {
        $report = $this->createReport(healthScores: [
            'overall' => new HealthScore('overall', 70.0, 'Acceptable', 60.0, 30.0),
            'complexity' => new HealthScore('complexity', 85.0, 'Good', 60.0, 30.0),
            'typing' => new HealthScore('typing', 75.0, 'Needs work', 80.0, 50.0),
        ]);
        $lines = [];

        $this->renderer->render($report, new FormatterContext(), $this->color, 80, false, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('Labels reflect per-dimension scales', $output);
    }

    public function testRenderNoScaleExplanationWhenSameThresholds(): void
    {
        $report = $this->createReport(healthScores: [
            'overall' => new HealthScore('overall', 70.0, 'Acceptable', 60.0, 30.0),
            'complexity' => new HealthScore('complexity', 85.0, 'Good', 60.0, 30.0),
            'coupling' => new HealthScore('coupling', 65.0, 'Acceptable', 60.0, 30.0),
        ]);
        $lines = [];

        $this->renderer->render($report, new FormatterContext(), $this->color, 80, false, $lines);

        $output = implode("\n", $lines);
        self::assertStringNotContainsString('Labels reflect per-dimension scales', $output);
    }

    public function testRenderC2DeltaLargeExplains(): void
    {
        [$report, $context] = $this->createC2DeltaFixture(
            overallScore: 75.0,
            flatScore: 50.0,
        );
        $lines = [];

        $this->renderer->render($report, $context, $this->color, 80, false, $lines);

        $output = implode("\n", $lines);
        // Delta is 25 (>10), so explanation is shown
        self::assertStringContainsString('direct classes: 50.0%', $output);
        self::assertStringContainsString('sub-namespaces raise the score', $output);
    }

    public function testRenderC2DeltaMediumShowsCompact(): void
    {
        [$report, $context] = $this->createC2DeltaFixture(
            overallScore: 75.0,
            flatScore: 68.0,
        );
        $lines = [];

        $this->renderer->render($report, $context, $this->color, 80, false, $lines);

        $output = implode("\n", $lines);
        // Delta is 7 (>5, <=10), so compact format is shown
        self::assertStringContainsString('(direct: 68.0%)', $output);
        self::assertStringNotContainsString('sub-namespaces raise the score', $output);
    }

    public function testRenderC2DeltaSmallHidden(): void
    {
        [$report, $context] = $this->createC2DeltaFixture(
            overallScore: 75.0,
            flatScore: 73.0,
        );
        $lines = [];

        $this->renderer->render($report, $context, $this->color, 80, false, $lines);

        $output = implode("\n", $lines);
        // Delta is 2 (<=5), so no hint at all
        self::assertStringNotContainsString('direct', $output);
    }

    public function testRenderOnlyOverallNoException(): void
    {
        $report = $this->createReport(healthScores: [
            'overall' => new HealthScore('overall', 90.0, 'Excellent', 60.0, 30.0),
        ]);
        $lines = [];

        $this->renderer->render($report, new FormatterContext(), $this->color, 80, false, $lines);

        // Only overall, no dimensions => should still render and end with empty line
        $output = implode("\n", $lines);
        self::assertStringContainsString('90%', $output);
        self::assertNotEmpty($lines);
        self::assertSame('', $lines[\count($lines) - 1]);
    }

    /**
     * Verifies bar fill character count for a known score.
     */
    public function testBarWidthCorrectness(): void
    {
        // At terminal width 80, barWidth = max(20, min(30, 80-50)) = 30
        // Score 50% => filled = round(50/100 * 30) = 15
        $report = $this->createReport(healthScores: [
            'overall' => new HealthScore('overall', 50.0, 'Needs work', 60.0, 30.0),
        ]);
        $lines = [];

        $this->renderer->render($report, new FormatterContext(), $this->color, 80, true, $lines);

        $healthLine = $lines[0];
        // Extract the bar between [ and ]
        if (preg_match('/\[([#.]+)]/', $healthLine, $matches) !== 1) {
            self::fail('Bar pattern not found in: ' . $healthLine);
        }
        $bar = $matches[1];
        self::assertSame(30, \strlen($bar));
        self::assertSame(15, substr_count($bar, '#'));
        self::assertSame(15, substr_count($bar, '.'));
    }

    /**
     * @return array<string, array{float, string}>
     */
    public static function colorRangeProvider(): array
    {
        return [
            'green score above warning' => [75.0, 'green'],
            'yellow score between warning and error' => [45.0, 'yellow'],
            'red score below error' => [20.0, 'red'],
        ];
    }

    #[DataProvider('colorRangeProvider')]
    public function testScoreColorByRange(float $score, string $expectedColor): void
    {
        $ansiColor = new AnsiColor(true);
        $resolver = new HealthScoreResolver(new NamespaceDrillDown(new MetricHintProvider()));
        $renderer = new HealthBarRenderer($resolver);

        $report = $this->createReport(healthScores: [
            'overall' => new HealthScore('overall', $score, 'Test', 60.0, 30.0),
        ]);
        $lines = [];

        $renderer->render($report, new FormatterContext(), $ansiColor, 80, false, $lines);

        $output = implode("\n", $lines);
        $ansiCodes = ['green' => "\e[32m", 'yellow' => "\e[33m", 'red' => "\e[31m"];
        self::assertStringContainsString($ansiCodes[$expectedColor], $output);
    }

    public function testRenderNegativeScoreTreatedAsZero(): void
    {
        $report = $this->createReport(healthScores: [
            'overall' => new HealthScore('overall', -10.0, 'Critical', 60.0, 30.0),
        ]);
        $lines = [];

        $this->renderer->render($report, new FormatterContext(), $this->color, 80, true, $lines);

        $healthLine = $lines[0];
        // Negative score: filled = max(0, min(30, round(-10/100*30))) = 0
        if (preg_match('/\[([#.]+)]/', $healthLine, $matches) !== 1) {
            self::fail('Bar pattern not found in: ' . $healthLine);
        }
        self::assertSame(0, substr_count($matches[1], '#'));
    }

    public function testRenderInfiniteScoreHandled(): void
    {
        $report = $this->createReport(healthScores: [
            'overall' => new HealthScore('overall', \INF, 'N/A', 60.0, 30.0),
        ]);
        $lines = [];

        $this->renderer->render($report, new FormatterContext(), $this->color, 80, false, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('—%', $output);
    }

    public function testRenderIntegerScoreOmitsDecimal(): void
    {
        $report = $this->createReport(healthScores: [
            'overall' => new HealthScore('overall', 80.0, 'Good', 60.0, 30.0),
        ]);
        $lines = [];

        $this->renderer->render($report, new FormatterContext(), $this->color, 80, false, $lines);

        $output = implode("\n", $lines);
        // Integer value should not show .0
        self::assertStringContainsString('80%', $output);
        self::assertStringNotContainsString('80.0%', $output);
    }

    /**
     * Creates a fixture for C2 delta hint tests.
     *
     * Sets up a metric repository with a namespace that has health scores,
     * so the resolver finds subtree scores and the renderer can compare them
     * with the flat (direct) health.overall score.
     *
     * @return array{Report, FormatterContext}
     */
    /**
     * Creates a fixture for C2 delta hint tests.
     *
     * Sets up two namespaces: parent (App\Service) with flatScore and
     * a child (App\Service\Payment) with a score that, combined, yields
     * the desired overallScore as a weighted average.
     *
     * @return array{Report, FormatterContext}
     */
    private function createC2DeltaFixture(float $overallScore, float $flatScore): array
    {
        $nsPath = SymbolPath::forNamespace('App\\Service');
        $childPath = SymbolPath::forNamespace('App\\Service\\Payment');

        // Parent namespace: flat score with 5 classes
        $parentBag = MetricBag::fromArray([
            'health.overall' => $flatScore,
            'classCount.sum' => 5,
        ]);

        // Calculate child score so the weighted average = overallScore
        // weighted avg = (flatScore*5 + childScore*5) / 10 = overallScore
        // childScore = 2*overallScore - flatScore
        $childScore = 2.0 * $overallScore - $flatScore;

        $childBag = MetricBag::fromArray([
            'health.overall' => $childScore,
            'classCount.sum' => 5,
        ]);

        $nsInfo = new SymbolInfo($nsPath, '/src/Service', null);
        $childInfo = new SymbolInfo($childPath, '/src/Service/Payment', null);

        $metrics = $this->createMock(MetricRepositoryInterface::class);
        $metrics->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$nsInfo, $childInfo]);
        $metrics->method('get')
            ->willReturnCallback(static fn(SymbolPath $path): MetricBag => match ($path->toCanonical()) {
                $nsPath->toCanonical() => $parentBag,
                $childPath->toCanonical() => $childBag,
                default => new MetricBag(),
            });

        $report = new Report(
            violations: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            metrics: $metrics,
            healthScores: [
                'overall' => new HealthScore('overall', $overallScore, 'Acceptable', 60.0, 30.0),
            ],
        );

        $context = new FormatterContext(namespace: 'App\\Service');

        return [$report, $context];
    }

    /**
     * @param array<string, HealthScore> $healthScores
     */
    private function createReport(array $healthScores = []): Report
    {
        return new Report(
            violations: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            healthScores: $healthScores,
        );
    }
}
