<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Debt\DebtCalculator;
use Qualimetrix\Reporting\Debt\RemediationTimeRegistry;
use Qualimetrix\Reporting\Formatter\Support\DetailedViolationRenderer;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;

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

    #[Test]
    public function itShowsNoViolationsFoundForEmptyViolations(): void
    {
        $context = new FormatterContext(useColor: false);
        $output = $this->renderer->render([], $context);

        self::assertStringContainsString('No violations found.', $output);
    }

    #[Test]
    public function itShowsScopedMessageForEmptyViolationsWithNamespaceFilter(): void
    {
        $context = new FormatterContext(useColor: false, namespace: 'App\\Service');
        $output = $this->renderer->render([], $context);

        self::assertStringContainsString('No violations in this scope.', $output);
    }

    #[Test]
    public function itShowsScopedMessageForEmptyViolationsWithClassFilter(): void
    {
        $context = new FormatterContext(useColor: false, class: 'App\\Service\\UserService');
        $output = $this->renderer->render([], $context);

        self::assertStringContainsString('No violations in this scope.', $output);
    }

    #[Test]
    public function itGroupsByFileByDefaultInDetailMode(): void
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

    #[Test]
    public function itRendersFlatWhenGroupByNoneIsExplicit(): void
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
        // But should have the violation with full path in the violation line (without line number for non-precise)
        self::assertStringContainsString('src/Foo.php', $output);
    }

    #[Test]
    public function itGroupsByRuleWhenGroupByRuleIsExplicit(): void
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

    #[Test]
    public function itUsesHumanMessageWhenAvailable(): void
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

    #[Test]
    public function itFallsBackToMessageWhenHumanMessageNull(): void
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

    #[Test]
    public function itShowsSeverityTagOnViolation(): void
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

    #[Test]
    public function itShowsRuleCodeOnViolation(): void
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

    #[Test]
    public function itShowsSymbolNameOnViolation(): void
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

    #[Test]
    public function itShowsDebtBreakdownByRule(): void
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

    #[Test]
    public function itUsesAllViolationsForDebtBreakdownWhenProvided(): void
    {
        $displayed = [
            new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'a'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
                message: 'Complex',
                severity: Severity::Error,
            ),
        ];

        $extra = new Violation(
            location: new Location('src/Bar.php', 5),
            symbolPath: SymbolPath::forClass('App', 'Bar'),
            ruleName: 'design.lcom',
            violationCode: 'design.lcom',
            message: 'LCOM high',
            severity: Severity::Warning,
        );

        $allViolations = [...$displayed, $extra];

        $context = new FormatterContext(useColor: false);
        $output = $this->renderer->render($displayed, $context, $allViolations);

        // Debt breakdown must include the rule from $allViolations, not just $displayed
        self::assertStringContainsString('design.lcom', $output);
        self::assertStringContainsString('complexity.cyclomatic', $output);
    }

    #[Test]
    public function itShowsProjectLevelViolationGroupHeader(): void
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

    #[Test]
    public function itRelativizesPathsWithBasePath(): void
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
