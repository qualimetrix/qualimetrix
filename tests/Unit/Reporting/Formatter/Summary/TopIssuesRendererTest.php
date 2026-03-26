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

        // File path on the first line (clickable in terminal)
        self::assertStringContainsString('HighImpactService.php', $output);
        self::assertStringContainsString('LowImpactService.php', $output);

        // Rule name and message on the second line
        self::assertStringContainsString('complexity.cyclomatic: Cyclomatic complexity is 45', $output);

        // Method-level symbol in parentheses
        self::assertStringContainsString('(HighImpactService::process)', $output);
        self::assertStringContainsString('(LowImpactService::process)', $output);
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

        self::assertStringContainsString('src/MyService.php', $output);
        self::assertStringNotContainsString('/home/user/project/', $output);
        // Line not shown because Location.precise defaults to false
        self::assertStringNotContainsString(':25', $output);
    }

    public function testRenderShowsLineNumberWhenPrecise(): void
    {
        $violation = new Violation(
            location: new Location('/project/src/Service.php', 42, precise: true),
            symbolPath: SymbolPath::forMethod('App\Service', 'Service', 'process'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Cyclomatic complexity is 45',
            severity: Severity::Error,
        );

        $report = new Report(
            violations: [],
            filesAnalyzed: 1,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 1,
            warningCount: 0,
            topIssues: [
                new RankedIssue(
                    violation: $violation,
                    impactScore: 10.0,
                    classRank: 0.05,
                    debtMinutes: 30,
                    severityWeight: 3,
                ),
            ],
        );

        $context = new FormatterContext(basePath: '/project');
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = implode("\n", $lines);

        self::assertStringContainsString('src/Service.php:42', $output);
    }

    public function testRenderShowsNamespaceLevelViolations(): void
    {
        $violation = new Violation(
            location: new Location('/project/src/Common/ApiResource/AbstractApiKey.php', null),
            symbolPath: SymbolPath::forNamespace('App\Common\ApiResource'),
            ruleName: 'size.namespace-size',
            violationCode: 'size.namespace-size',
            message: 'Namespace contains 25 classes, exceeds threshold of 15',
            severity: Severity::Error,
        );

        $report = new Report(
            violations: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 1,
            warningCount: 0,
            topIssues: [
                new RankedIssue(
                    violation: $violation,
                    impactScore: 3.14,
                    classRank: null,
                    debtMinutes: 45,
                    severityWeight: 3,
                ),
            ],
        );

        $context = new FormatterContext(basePath: '/project');
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = implode("\n", $lines);

        // File path on first line
        self::assertStringContainsString('src/Common/ApiResource/AbstractApiKey.php', $output);

        // Rule and message on second line
        self::assertStringContainsString('size.namespace-size: Namespace contains 25 classes', $output);

        // Namespace context in parentheses
        self::assertStringContainsString('(namespace: App\Common\ApiResource)', $output);
    }

    public function testRenderShowsClassLevelWithoutSymbolSuffix(): void
    {
        $violation = new Violation(
            location: new Location('/project/src/Service/UserService.php', 5),
            symbolPath: SymbolPath::forClass('App\Service', 'UserService'),
            ruleName: 'coupling.cbo',
            violationCode: 'coupling.cbo',
            message: 'Coupling between objects is 20, exceeds threshold of 13',
            severity: Severity::Warning,
        );

        $report = new Report(
            violations: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 1,
            topIssues: [
                new RankedIssue(
                    violation: $violation,
                    impactScore: 2.5,
                    classRank: 0.03,
                    debtMinutes: 30,
                    severityWeight: 1,
                ),
            ],
        );

        $context = new FormatterContext(basePath: '/project');
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = implode("\n", $lines);

        // Rule and message shown
        self::assertStringContainsString('coupling.cbo: Coupling between objects is 20', $output);

        // Class-level symbol NOT appended (redundant with file path)
        self::assertStringNotContainsString('(UserService)', $output);
    }

    public function testRenderShowsFunctionLevelSymbol(): void
    {
        $violation = new Violation(
            location: new Location('/project/src/helpers.php', 10, precise: true),
            symbolPath: SymbolPath::forGlobalFunction('App\Utils', 'calculateHash'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Cyclomatic complexity is 30',
            severity: Severity::Error,
        );

        $report = new Report(
            violations: [],
            filesAnalyzed: 1,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 1,
            warningCount: 0,
            topIssues: [
                new RankedIssue(violation: $violation, impactScore: 5.0, classRank: null, debtMinutes: 20, severityWeight: 3),
            ],
        );

        $context = new FormatterContext(basePath: '/project');
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = implode("\n", $lines);

        self::assertStringContainsString('(calculateHash)', $output);
    }

    public function testRenderShowsFileLevelWithoutSymbolSuffix(): void
    {
        $violation = new Violation(
            location: new Location('/project/src/config.php', null),
            symbolPath: SymbolPath::forFile('src/config.php'),
            ruleName: 'security.hardcoded-credentials',
            violationCode: 'security.hardcoded-credentials',
            message: 'Hardcoded credentials detected',
            severity: Severity::Error,
        );

        $report = new Report(
            violations: [],
            filesAnalyzed: 1,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 1,
            warningCount: 0,
            topIssues: [
                new RankedIssue(violation: $violation, impactScore: 8.0, classRank: null, debtMinutes: 15, severityWeight: 3),
            ],
        );

        $context = new FormatterContext(basePath: '/project');
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = implode("\n", $lines);

        self::assertStringContainsString('security.hardcoded-credentials: Hardcoded credentials detected', $output);
        // File-level symbol should NOT append suffix
        self::assertStringNotContainsString('(src/config.php)', $output);
    }

    public function testRenderHandlesLocationNone(): void
    {
        $violation = new Violation(
            location: Location::none(),
            symbolPath: SymbolPath::forProject(),
            ruleName: 'architecture.circular-dependency',
            violationCode: 'architecture.circular-dependency',
            message: 'Circular dependency detected: A -> B -> A',
            severity: Severity::Error,
        );

        $report = new Report(
            violations: [],
            filesAnalyzed: 1,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 1,
            warningCount: 0,
            topIssues: [
                new RankedIssue(violation: $violation, impactScore: 1.0, classRank: null, debtMinutes: 60, severityWeight: 3),
            ],
        );

        $context = new FormatterContext();
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = implode("\n", $lines);

        self::assertStringContainsString('[project]', $output);
        self::assertStringContainsString('architecture.circular-dependency: Circular dependency detected', $output);
    }

    public function testRenderPrefersRecommendationOverMessage(): void
    {
        $violation = new Violation(
            location: new Location('/project/src/Service.php', 5),
            symbolPath: SymbolPath::forClass('App\Service', 'Service'),
            ruleName: 'design.lcom',
            violationCode: 'design.lcom',
            message: 'LCOM4 value 3 exceeds threshold of 2',
            severity: Severity::Warning,
            recommendation: 'Class could be split into 3 cohesive parts',
        );

        $report = new Report(
            violations: [],
            filesAnalyzed: 1,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 1,
            topIssues: [
                new RankedIssue(violation: $violation, impactScore: 2.0, classRank: 0.01, debtMinutes: 45, severityWeight: 1),
            ],
        );

        $context = new FormatterContext(basePath: '/project');
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = implode("\n", $lines);

        // Should show recommendation, not technical message
        self::assertStringContainsString('Class could be split into 3 cohesive parts', $output);
        self::assertStringNotContainsString('LCOM4 value 3 exceeds threshold', $output);
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
