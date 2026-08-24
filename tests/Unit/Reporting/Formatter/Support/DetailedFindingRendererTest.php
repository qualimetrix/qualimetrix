<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Finding\Contract\AcceptedLevel;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Formatter\Support\DebtBreakdownRenderer;
use Qualimetrix\Reporting\Formatter\Support\DetailedFindingRenderer;
use Qualimetrix\Reporting\Formatter\Support\FindingDetailRenderer;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Tests\Analysis\Evidence\Prioritization\Support\StubRemediationMinutes;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;

#[CoversClass(DetailedFindingRenderer::class)]
#[CoversClass(FindingDetailRenderer::class)]
#[CoversClass(DebtBreakdownRenderer::class)]
final class DetailedFindingRendererTest extends TestCase
{
    private DetailedFindingRenderer $renderer;
    private FindingDetailRenderer $detailRenderer;
    private DebtBreakdownRenderer $debtRenderer;

    protected function setUp(): void
    {
        $debtCalculator = new DebtCalculator(new RemediationTimeRegistry(StubChannelDeclarationRegistry::alwaysHigherMagnitude(), StubRemediationMinutes::withRealValues()));
        $this->renderer = new DetailedFindingRenderer($debtCalculator);
        $this->detailRenderer = new FindingDetailRenderer();
        $this->debtRenderer = new DebtBreakdownRenderer($debtCalculator);
    }

    #[Test]
    public function itShowsNoFindingsFoundForEmptyFindings(): void
    {
        $context = new FormatterContext(useColor: false);
        $output = $this->renderer->render([], $context);

        self::assertStringContainsString('No violations found.', $output);
    }

    #[Test]
    public function itShowsScopedMessageForEmptyFindingsWithNamespaceFilter(): void
    {
        $context = new FormatterContext(useColor: false, namespace: 'App\\Service');
        $output = $this->renderer->render([], $context);

        self::assertStringContainsString('No violations in this scope.', $output);
    }

    #[Test]
    public function itShowsScopedMessageForEmptyFindingsWithClassFilter(): void
    {
        $context = new FormatterContext(useColor: false, class: 'App\\Service\\UserService');
        $output = $this->renderer->render([], $context);

        self::assertStringContainsString('No violations in this scope.', $output);
    }

    #[Test]
    public function itGroupsByFileByDefaultInDetailMode(): void
    {
        $findings = [
            self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'test',
                code: 'test.rule',
                message: 'Test msg',
                severity: Severity::Error,
            ),
            self::finding(
                location: new Location(RelativePath::fromString('src/Bar.php'), 20),
                symbolPath: SymbolPath::forClass('App', 'Bar'),
                ruleName: 'test',
                code: 'test.rule',
                message: 'Bar msg',
                severity: Severity::Warning,
            ),
        ];

        $context = new FormatterContext(useColor: false);
        $output = $this->renderer->render($findings, $context);

        // Should group by file (default in detail mode)
        self::assertStringContainsString('src/Foo.php (1 violation)', $output);
        self::assertStringContainsString('src/Bar.php (1 violation)', $output);
        self::assertStringContainsString('Technical debt by rule:', $output);
    }

    #[Test]
    public function itRendersFlatWhenGroupByNoneIsExplicit(): void
    {
        $findings = [
            self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'test',
                code: 'test.rule',
                message: 'Test msg',
                severity: Severity::Error,
            ),
        ];

        $context = new FormatterContext(useColor: false, groupBy: GroupBy::None, isGroupByExplicit: true);
        $output = $this->detailRenderer->render($findings, $context);

        // Should NOT have file group headers (but debt breakdown may mention "violation")
        self::assertStringNotContainsString('src/Foo.php (1 violation)', $output);
        // But should have the finding with full path in the finding line (without line number for non-precise)
        self::assertStringContainsString('src/Foo.php', $output);
    }

    #[Test]
    public function itGroupsByRuleWhenGroupByRuleIsExplicit(): void
    {
        $findings = [
            self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic.callable',
                message: 'Complex',
                severity: Severity::Error,
            ),
            self::finding(
                location: new Location(RelativePath::fromString('src/Bar.php'), 5),
                symbolPath: SymbolPath::forClass('App', 'Bar'),
                ruleName: 'size.method-count',
                code: 'size.method-count',
                message: 'Too many',
                severity: Severity::Warning,
            ),
        ];

        $context = new FormatterContext(useColor: false, groupBy: GroupBy::Rule, isGroupByExplicit: true);
        $output = $this->detailRenderer->render($findings, $context);

        self::assertStringContainsString('complexity.cyclomatic (1)', $output);
        self::assertStringContainsString('size.method-count (1)', $output);
    }

    #[Test]
    public function itUsesHumanMessageWhenAvailable(): void
    {
        $findings = [
            self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic.callable',
                message: 'Cyclomatic complexity is 15, exceeds threshold of 10',
                severity: Severity::Error,
                metricValue: 15,
                recommendation: 'Cyclomatic complexity: 15 (threshold: 10) — too many code paths',
            ),
        ];

        $context = new FormatterContext(useColor: false);
        $output = $this->detailRenderer->render($findings, $context);

        self::assertStringContainsString('too many code paths', $output);
        self::assertStringNotContainsString('exceeds threshold', $output);
    }

    #[Test]
    public function itFallsBackToMessageWhenHumanMessageNull(): void
    {
        $findings = [
            self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic.callable',
                message: 'Cyclomatic complexity is 15, exceeds threshold of 10',
                severity: Severity::Error,
                metricValue: 15,
            ),
        ];

        $context = new FormatterContext(useColor: false);
        $output = $this->detailRenderer->render($findings, $context);

        self::assertStringContainsString('exceeds threshold', $output);
    }

    #[Test]
    public function itShowsSeverityTagOnFinding(): void
    {
        $findings = [
            self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'test',
                code: 'test.rule',
                message: 'Error msg',
                severity: Severity::Error,
            ),
            self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 20),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'test',
                code: 'test.rule',
                message: 'Warn msg',
                severity: Severity::Warning,
            ),
        ];

        $context = new FormatterContext(useColor: false);
        $output = $this->detailRenderer->render($findings, $context);

        self::assertStringContainsString('ERROR', $output);
        self::assertStringContainsString('WARN', $output);
    }

    #[Test]
    public function itShowsRuleCodeOnFinding(): void
    {
        $findings = [
            self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic.callable',
                message: 'Test',
                severity: Severity::Error,
            ),
        ];

        $context = new FormatterContext(useColor: false);
        $output = $this->detailRenderer->render($findings, $context);

        self::assertStringContainsString('[complexity.cyclomatic.callable]', $output);
    }

    #[Test]
    public function itShowsSymbolNameOnFinding(): void
    {
        $findings = [
            self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'test',
                code: 'test.rule',
                message: 'Test',
                severity: Severity::Error,
            ),
        ];

        $context = new FormatterContext(useColor: false);
        $output = $this->detailRenderer->render($findings, $context);

        self::assertStringContainsString('bar', $output);
    }

    #[Test]
    public function itShowsDebtBreakdownByRule(): void
    {
        $findings = [
            self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'a'),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic.callable',
                message: 'Complex',
                severity: Severity::Error,
            ),
            self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 20),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'b'),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic.callable',
                message: 'Complex',
                severity: Severity::Error,
            ),
            self::finding(
                location: new Location(RelativePath::fromString('src/Bar.php'), 5),
                symbolPath: SymbolPath::forClass('App', 'Bar'),
                ruleName: 'cohesion.lcom',
                code: 'cohesion.lcom',
                message: 'LCOM high',
                severity: Severity::Warning,
            ),
        ];

        $output = $this->debtRenderer->render($findings);

        self::assertStringContainsString('Technical debt by rule:', $output);
        self::assertStringContainsString('complexity.cyclomatic', $output);
        self::assertStringContainsString('2 violations', $output);
        self::assertStringContainsString('cohesion.lcom', $output);
        self::assertStringContainsString('1 violation', $output);
    }

    #[Test]
    public function itUsesAllFindingsForDebtBreakdownWhenProvided(): void
    {
        $displayed = [
            self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'a'),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic.callable',
                message: 'Complex',
                severity: Severity::Error,
            ),
        ];

        $extra = self::finding(
            location: new Location(RelativePath::fromString('src/Bar.php'), 5),
            symbolPath: SymbolPath::forClass('App', 'Bar'),
            ruleName: 'cohesion.lcom',
            code: 'cohesion.lcom',
            message: 'LCOM high',
            severity: Severity::Warning,
        );

        $allFindings = [...$displayed, $extra];

        $output = $this->debtRenderer->render($displayed, $allFindings);

        // Debt breakdown must include the rule from $allFindings, not just $displayed
        self::assertStringContainsString('cohesion.lcom', $output);
        self::assertStringContainsString('complexity.cyclomatic', $output);
    }

    #[Test]
    public function itShowsTheAcceptedLevelOnABreach(): void
    {
        $findings = [
            (self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic.callable',
                message: 'Complexity is 31',
                severity: Severity::Warning,
                metricValue: 31,
            ))->reportedAsBreach(new AcceptedLevel([25.0], 1)),
        ];

        $context = new FormatterContext(useColor: false);
        $output = $this->detailRenderer->render($findings, $context);

        self::assertStringContainsString('Complexity is 31 (accepted at 25, now 31)', $output);
    }

    #[Test]
    public function itOmitsTheAcceptedLevelFragmentWhenAbsent(): void
    {
        $findings = [
            self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic.callable',
                message: 'Complexity is 31',
                severity: Severity::Warning,
                metricValue: 31,
            ),
        ];

        $context = new FormatterContext(useColor: false);
        $output = $this->detailRenderer->render($findings, $context);

        self::assertStringNotContainsString('accepted at', $output);
    }

    #[Test]
    public function itShowsProjectLevelFindingGroupHeader(): void
    {
        $findings = [
            self::finding(
                location: Location::none(),
                symbolPath: SymbolPath::forNamespace('App\\Service'),
                ruleName: 'architecture.circular-dependency',
                code: 'architecture.circular-dependency',
                message: 'Circular dependency detected',
                severity: Severity::Error,
            ),
        ];

        $context = new FormatterContext(useColor: false);
        $output = $this->detailRenderer->render($findings, $context);

        self::assertStringContainsString('[project]', $output);
    }
    /** @param list<\Qualimetrix\Analysis\Finding\Contract\Location> $relatedLocations */
    private static function finding(\Qualimetrix\Analysis\Finding\Contract\Location $location, \Qualimetrix\Core\Symbol\SymbolPath $symbolPath, string $ruleName, string $code, string $message, \Qualimetrix\Analysis\Finding\Contract\Severity $severity, int|float|null $metricValue = null, array $relatedLocations = [], ?string $recommendation = null, int|float|null $threshold = null, ?\Qualimetrix\Core\Symbol\SymbolPath $dependencyTarget = null, ?\Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType $dependencyType = null, ?\Qualimetrix\Analysis\Finding\Contract\AcceptedLevel $acceptedLevel = null, ?\Qualimetrix\Analysis\Finding\Contract\OccurrenceKey $occurrenceKey = null, ?\Qualimetrix\Core\Symbol\MetricSubject $subject = null): Finding
    {
        $subject ??= match ($symbolPath->getType()) {
            \Qualimetrix\Core\Symbol\SymbolType::File, \Qualimetrix\Core\Symbol\SymbolType::Namespace_, \Qualimetrix\Core\Symbol\SymbolType::Project => \Qualimetrix\Core\Symbol\MetricSubject::aggregate($symbolPath),
            default => \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of($symbolPath, $location->file ?? \Qualimetrix\Core\Path\RelativePath::fromString('tests/Reporting/fixture.php'), \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
        };
        return new Finding(location: $location, subject: $subject, symbolPath: $symbolPath, ruleName: $ruleName, code: $code, message: $message, severity: $severity, metricValue: $metricValue, relatedLocations: $relatedLocations, recommendation: $recommendation, threshold: $threshold, dependencyTarget: $dependencyTarget, dependencyType: $dependencyType, acceptedLevel: $acceptedLevel, occurrenceKey: $occurrenceKey);
    }

}
