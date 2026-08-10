<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\AcceptedLevel;
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
            self::violation(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'test',
                violationCode: 'test.rule',
                message: 'Test msg',
                severity: Severity::Error,
            ),
            self::violation(
                location: new Location(RelativePath::fromString('src/Bar.php'), 20),
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
            self::violation(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
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
            self::violation(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.callable',
                message: 'Complex',
                severity: Severity::Error,
            ),
            self::violation(
                location: new Location(RelativePath::fromString('src/Bar.php'), 5),
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
            self::violation(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.callable',
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
            self::violation(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.callable',
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
            self::violation(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'test',
                violationCode: 'test.rule',
                message: 'Error msg',
                severity: Severity::Error,
            ),
            self::violation(
                location: new Location(RelativePath::fromString('src/Foo.php'), 20),
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
            self::violation(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.callable',
                message: 'Test',
                severity: Severity::Error,
            ),
        ];

        $context = new FormatterContext(useColor: false);
        $output = $this->renderer->render($violations, $context);

        self::assertStringContainsString('[complexity.cyclomatic.callable]', $output);
    }

    #[Test]
    public function itShowsSymbolNameOnViolation(): void
    {
        $violations = [
            self::violation(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
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
            self::violation(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'a'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.callable',
                message: 'Complex',
                severity: Severity::Error,
            ),
            self::violation(
                location: new Location(RelativePath::fromString('src/Foo.php'), 20),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'b'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.callable',
                message: 'Complex',
                severity: Severity::Error,
            ),
            self::violation(
                location: new Location(RelativePath::fromString('src/Bar.php'), 5),
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
            self::violation(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'a'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.callable',
                message: 'Complex',
                severity: Severity::Error,
            ),
        ];

        $extra = self::violation(
            location: new Location(RelativePath::fromString('src/Bar.php'), 5),
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
    public function itShowsTheAcceptedLevelOnABreach(): void
    {
        $violations = [
            (self::violation(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.callable',
                message: 'Complexity is 31',
                severity: Severity::Warning,
                metricValue: 31,
            ))->reportedAsBreach(new AcceptedLevel([25.0], 1)),
        ];

        $context = new FormatterContext(useColor: false);
        $output = $this->renderer->render($violations, $context);

        self::assertStringContainsString('Complexity is 31 (accepted at 25, now 31)', $output);
    }

    #[Test]
    public function itOmitsTheAcceptedLevelFragmentWhenAbsent(): void
    {
        $violations = [
            self::violation(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.callable',
                message: 'Complexity is 31',
                severity: Severity::Warning,
                metricValue: 31,
            ),
        ];

        $context = new FormatterContext(useColor: false);
        $output = $this->renderer->render($violations, $context);

        self::assertStringNotContainsString('accepted at', $output);
    }

    #[Test]
    public function itShowsProjectLevelViolationGroupHeader(): void
    {
        $violations = [
            self::violation(
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
    /** @param list<\Qualimetrix\Core\Violation\Location> $relatedLocations */
    private static function violation(\Qualimetrix\Core\Violation\Location $location, \Qualimetrix\Core\Symbol\SymbolPath $symbolPath, string $ruleName, string $violationCode, string $message, \Qualimetrix\Core\Violation\Severity $severity, int|float|null $metricValue = null, ?\Qualimetrix\Core\Rule\RuleLevel $level = null, array $relatedLocations = [], ?string $recommendation = null, int|float|null $threshold = null, ?\Qualimetrix\Core\Symbol\SymbolPath $dependencyTarget = null, ?\Qualimetrix\Core\Dependency\DependencyType $dependencyType = null, ?\Qualimetrix\Core\Violation\AcceptedLevel $acceptedLevel = null, ?\Qualimetrix\Core\Violation\OccurrenceKey $occurrenceKey = null, ?\Qualimetrix\Core\Symbol\MetricSubject $subject = null): Violation
    {
        $subject ??= match ($symbolPath->getType()) {
            \Qualimetrix\Core\Symbol\SymbolType::File, \Qualimetrix\Core\Symbol\SymbolType::Namespace_, \Qualimetrix\Core\Symbol\SymbolType::Project => \Qualimetrix\Core\Symbol\MetricSubject::aggregate($symbolPath),
            default => \Qualimetrix\Core\Symbol\MetricSubject::declaration(new \Qualimetrix\Core\Symbol\DeclarationPath($symbolPath, $location->file ?? \Qualimetrix\Core\Path\RelativePath::fromString('tests/Reporting/fixture.php'), $location->line ?? 0)),
        };
        return new Violation(location: $location, subject: $subject, symbolPath: $symbolPath, ruleName: $ruleName, violationCode: $violationCode, message: $message, severity: $severity, metricValue: $metricValue, level: $level, relatedLocations: $relatedLocations, recommendation: $recommendation, threshold: $threshold, dependencyTarget: $dependencyTarget, dependencyType: $dependencyType, acceptedLevel: $acceptedLevel, occurrenceKey: $occurrenceKey);
    }

}
