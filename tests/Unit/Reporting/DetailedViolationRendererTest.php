<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Reporting;

use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Debt\DebtCalculator;
use AiMessDetector\Reporting\Debt\RemediationTimeRegistry;
use AiMessDetector\Reporting\DetailedViolationRenderer;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DetailedViolationRenderer::class)]
final class DetailedViolationRendererTest extends TestCase
{
    private DetailedViolationRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new DetailedViolationRenderer(
            new DebtCalculator(new RemediationTimeRegistry()),
        );
    }

    public function testEmptyViolationsShowsNoViolationsFound(): void
    {
        $context = new FormatterContext(useColor: false);
        $output = $this->renderer->render([], $context);

        self::assertStringContainsString('No violations found.', $output);
    }

    public function testEmptyViolationsWithNamespaceFilterShowsScopedMessage(): void
    {
        $context = new FormatterContext(useColor: false, namespace: 'App\\Service');
        $output = $this->renderer->render([], $context);

        self::assertStringContainsString('No violations in this scope.', $output);
    }

    public function testEmptyViolationsWithClassFilterShowsScopedMessage(): void
    {
        $context = new FormatterContext(useColor: false, class: 'App\\Service\\UserService');
        $output = $this->renderer->render([], $context);

        self::assertStringContainsString('No violations in this scope.', $output);
    }

    public function testDefaultGroupByIsFileInDetailMode(): void
    {
        $violations = [
            new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'test',
                violationCode: 'test.rule',
                message: 'Test msg',
                severity: Severity::Error,
            ),
            new Violation(
                location: new Location('src/Bar.php', 20),
                symbolPath: SymbolPath::forClass('App', 'Bar'),
                ruleName: 'test',
                violationCode: 'test.rule',
                message: 'Bar msg',
                severity: Severity::Warning,
            ),
        ];

        $context = new FormatterContext(useColor: false);
        $output = $this->renderer->render($violations, $context);

        // Should group by file (default in detail mode)
        self::assertStringContainsString('src/Foo.php (1 violation)', $output);
        self::assertStringContainsString('src/Bar.php (1 violation)', $output);
    }

    public function testExplicitGroupByNoneRendersFlat(): void
    {
        $violations = [
            new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'test',
                violationCode: 'test.rule',
                message: 'Test msg',
                severity: Severity::Error,
            ),
        ];

        $context = new FormatterContext(useColor: false, groupBy: GroupBy::None, isGroupByExplicit: true);
        $output = $this->renderer->render($violations, $context);

        // Should NOT have file group headers (but debt breakdown may mention "violation")
        self::assertStringNotContainsString('src/Foo.php (1 violation)', $output);
        // But should have the violation with full path in the violation line
        self::assertStringContainsString('src/Foo.php:10', $output);
    }

    public function testExplicitGroupByRuleGroupsByRule(): void
    {
        $violations = [
            new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
                message: 'Complex',
                severity: Severity::Error,
            ),
            new Violation(
                location: new Location('src/Bar.php', 5),
                symbolPath: SymbolPath::forClass('App', 'Bar'),
                ruleName: 'size.method-count',
                violationCode: 'size.method-count',
                message: 'Too many',
                severity: Severity::Warning,
            ),
        ];

        $context = new FormatterContext(useColor: false, groupBy: GroupBy::Rule, isGroupByExplicit: true);
        $output = $this->renderer->render($violations, $context);

        self::assertStringContainsString('complexity.cyclomatic (1)', $output);
        self::assertStringContainsString('size.method-count (1)', $output);
    }

    public function testUsesHumanMessageWhenAvailable(): void
    {
        $violations = [
            new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
                message: 'Cyclomatic complexity is 15, exceeds threshold of 10',
                severity: Severity::Error,
                metricValue: 15,
                recommendation: 'Cyclomatic complexity: 15 (threshold: 10) — too many code paths',
            ),
        ];

        $context = new FormatterContext(useColor: false);
        $output = $this->renderer->render($violations, $context);

        self::assertStringContainsString('too many code paths', $output);
        self::assertStringNotContainsString('exceeds threshold', $output);
    }

    public function testFallsBackToMessageWhenHumanMessageNull(): void
    {
        $violations = [
            new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
                message: 'Cyclomatic complexity is 15, exceeds threshold of 10',
                severity: Severity::Error,
                metricValue: 15,
            ),
        ];

        $context = new FormatterContext(useColor: false);
        $output = $this->renderer->render($violations, $context);

        self::assertStringContainsString('exceeds threshold', $output);
    }

    public function testViolationShowsSeverityTag(): void
    {
        $violations = [
            new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'test',
                violationCode: 'test.rule',
                message: 'Error msg',
                severity: Severity::Error,
            ),
            new Violation(
                location: new Location('src/Foo.php', 20),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'test',
                violationCode: 'test.rule',
                message: 'Warn msg',
                severity: Severity::Warning,
            ),
        ];

        $context = new FormatterContext(useColor: false);
        $output = $this->renderer->render($violations, $context);

        self::assertStringContainsString('ERROR', $output);
        self::assertStringContainsString('WARN', $output);
    }

    public function testViolationShowsRuleCode(): void
    {
        $violations = [
            new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
                message: 'Test',
                severity: Severity::Error,
            ),
        ];

        $context = new FormatterContext(useColor: false);
        $output = $this->renderer->render($violations, $context);

        self::assertStringContainsString('[complexity.cyclomatic.method]', $output);
    }

    public function testViolationShowsSymbolName(): void
    {
        $violations = [
            new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'test',
                violationCode: 'test.rule',
                message: 'Test',
                severity: Severity::Error,
            ),
        ];

        $context = new FormatterContext(useColor: false);
        $output = $this->renderer->render($violations, $context);

        self::assertStringContainsString('bar', $output);
    }

    public function testDebtBreakdownByRule(): void
    {
        $violations = [
            new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'a'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
                message: 'Complex',
                severity: Severity::Error,
            ),
            new Violation(
                location: new Location('src/Foo.php', 20),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'b'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
                message: 'Complex',
                severity: Severity::Error,
            ),
            new Violation(
                location: new Location('src/Bar.php', 5),
                symbolPath: SymbolPath::forClass('App', 'Bar'),
                ruleName: 'design.lcom',
                violationCode: 'design.lcom',
                message: 'LCOM high',
                severity: Severity::Warning,
            ),
        ];

        $context = new FormatterContext(useColor: false);
        $output = $this->renderer->render($violations, $context);

        self::assertStringContainsString('Technical debt by rule:', $output);
        self::assertStringContainsString('complexity.cyclomatic', $output);
        self::assertStringContainsString('2 violations', $output);
        self::assertStringContainsString('design.lcom', $output);
        self::assertStringContainsString('1 violation', $output);
    }

    public function testProjectLevelViolationGroupHeader(): void
    {
        $violations = [
            new Violation(
                location: Location::none(),
                symbolPath: SymbolPath::forNamespace('App\\Service'),
                ruleName: 'architecture.circular-dependency',
                violationCode: 'architecture.circular-dependency',
                message: 'Circular dependency detected',
                severity: Severity::Error,
            ),
        ];

        $context = new FormatterContext(useColor: false);
        $output = $this->renderer->render($violations, $context);

        self::assertStringContainsString('[project]', $output);
    }

    public function testRelativizesPathsWithBasePath(): void
    {
        $violations = [
            new Violation(
                location: new Location('/home/user/project/src/Foo.php', 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Test',
                severity: Severity::Error,
            ),
        ];

        $context = new FormatterContext(useColor: false, basePath: '/home/user/project');
        $output = $this->renderer->render($violations, $context);

        self::assertStringContainsString('src/Foo.php', $output);
        self::assertStringNotContainsString('/home/user/project/', $output);
    }
}
