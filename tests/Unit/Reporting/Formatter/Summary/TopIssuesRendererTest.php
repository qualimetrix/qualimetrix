<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Summary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Formatter\Summary\TopIssuesRenderer;
use Qualimetrix\Reporting\Formatter\Support\AnsiColor;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Impact\RankedIssue;
use Qualimetrix\Reporting\Report;

#[CoversClass(TopIssuesRenderer::class)]
final class TopIssuesRendererTest extends TestCase
{
    private TopIssuesRenderer $renderer;
    private AnsiColor $color;

    protected function setUp(): void
    {
        $this->renderer = new TopIssuesRenderer();
        $this->color = new AnsiColor(false);
    }

    public function testRenderShowsTopIssues(): void
    {
        $report = new Report(
            violations: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 1,
            warningCount: 1,
            topIssues: [
                $this->createRankedIssue(150.0, Severity::Error, 'HighImpactService', '/project/src/HighImpactService.php', 42, 60),
                $this->createRankedIssue(30.0, Severity::Warning, 'LowImpactService', '/project/src/LowImpactService.php', 10, 15),
            ],
        );

        $context = new FormatterContext();
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = implode("\n", $lines);

        self::assertStringContainsString('Top issues by impact', $output);
        self::assertStringContainsString('1.', $output);
        self::assertStringContainsString('2.', $output);
        self::assertStringContainsString('ERR', $output);
        self::assertStringContainsString('WRN', $output);
        self::assertStringContainsString('App\Service\HighImpactService::process', $output);
        self::assertStringContainsString('App\Service\LowImpactService::process', $output);
    }

    public function testRenderSkipsWhenNoTopIssues(): void
    {
        $report = new Report(
            violations: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            topIssues: [],
        );

        $context = new FormatterContext();
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        self::assertSame([], $lines);
    }

    public function testRenderSkipsWhenLimitIsZero(): void
    {
        $report = new Report(
            violations: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 1,
            warningCount: 0,
            topIssues: [
                $this->createRankedIssue(100.0, Severity::Error, 'SomeService', '/project/src/SomeService.php', 5, 30),
            ],
        );

        $context = new FormatterContext(topIssuesLimit: 0);
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        self::assertSame([], $lines);
    }

    public function testRenderRespectsLimit(): void
    {
        $report = new Report(
            violations: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 3,
            warningCount: 0,
            topIssues: [
                $this->createRankedIssue(200.0, Severity::Error, 'First', '/project/src/First.php', 1, 60),
                $this->createRankedIssue(100.0, Severity::Error, 'Second', '/project/src/Second.php', 2, 30),
                $this->createRankedIssue(50.0, Severity::Error, 'Third', '/project/src/Third.php', 3, 15),
            ],
        );

        $context = new FormatterContext(topIssuesLimit: 1);
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = implode("\n", $lines);

        self::assertStringContainsString('1.', $output);
        self::assertStringNotContainsString('2.', $output);
    }

    public function testRenderRelativizesPaths(): void
    {
        $report = new Report(
            violations: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 1,
            warningCount: 0,
            topIssues: [
                $this->createRankedIssue(100.0, Severity::Error, 'MyService', '/home/user/project/src/MyService.php', 25, 30),
            ],
        );

        $context = new FormatterContext(basePath: '/home/user/project');
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = implode("\n", $lines);

        self::assertStringContainsString('src/MyService.php:25', $output);
        self::assertStringNotContainsString('/home/user/project/', $output);
    }

    private function createRankedIssue(
        float $score,
        Severity $severity,
        string $symbol,
        string $file,
        int $line,
        int $debt,
    ): RankedIssue {
        $violation = new Violation(
            location: new Location($file, $line),
            symbolPath: SymbolPath::forMethod('App\Service', $symbol, 'process'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Cyclomatic complexity is 45',
            severity: $severity,
        );

        return new RankedIssue(
            violation: $violation,
            impactScore: $score,
            classRank: 0.05,
            debtMinutes: $debt,
            severityWeight: $severity === Severity::Error ? 3 : 1,
        );
    }
}
