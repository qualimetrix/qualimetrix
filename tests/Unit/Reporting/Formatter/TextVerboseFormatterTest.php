<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Formatter\Support\DetailedFindingRenderer;
use Qualimetrix\Reporting\Formatter\TextFormatter;
use Qualimetrix\Reporting\Formatter\TextVerboseFormatter;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Report;
use Qualimetrix\Reporting\ReportBuilder;
use Qualimetrix\Tests\Analysis\Evidence\Prioritization\Support\StubRemediationMinutes;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;

/**
 * Tests that TextVerboseFormatter correctly delegates to TextFormatter with detail=true.
 */
#[CoversClass(TextVerboseFormatter::class)]
final class TextVerboseFormatterTest extends TestCase
{
    private TextVerboseFormatter $formatter;
    private TextFormatter $textFormatter;
    private FormatterContext $plainContext;

    protected function setUp(): void
    {
        $debtCalculator = new DebtCalculator(new RemediationTimeRegistry(StubChannelDeclarationRegistry::alwaysHigherMagnitude(), StubRemediationMinutes::withRealValues()));
        $detailedRenderer = new DetailedFindingRenderer($debtCalculator);
        $this->textFormatter = new TextFormatter($debtCalculator, $detailedRenderer);
        $this->formatter = new TextVerboseFormatter($this->textFormatter);
        $this->plainContext = new FormatterContext(useColor: false, groupBy: GroupBy::File);
    }

    #[Test]
    public function itReturnsTextVerboseName(): void
    {
        self::assertSame('text-verbose', $this->formatter->getName());
    }

    #[Test]
    public function itReturnsDefaultGroupByFile(): void
    {
        self::assertSame(GroupBy::File, $this->formatter->getDefaultGroupBy());
    }

    #[Test]
    public function itDelegatesToTextFormatterWithDetailEnabled(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 42),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic',
                message: 'Cyclomatic complexity is 15',
                severity: Severity::Error,
                metricValue: 15,
                recommendation: 'Cyclomatic complexity: 15 (threshold: 10) — too many code paths',
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $context = new FormatterContext(useColor: false, groupBy: GroupBy::File);
        $verboseOutput = $this->formatter->format($report, $context);

        // Should produce detail-mode output (same as TextFormatter --detail)
        $detailContext = new FormatterContext(useColor: false, groupBy: GroupBy::File, detailLimit: 0);
        $detailOutput = $this->textFormatter->format($report, $detailContext);

        self::assertSame($detailOutput, $verboseOutput);
    }

    #[Test]
    public function itFormatsEmptyReport(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(42)
            ->filesSkipped(0)
            ->duration(0.15)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('No violations found.', $output);
        self::assertStringContainsString('0 error(s), 0 warning(s) in 42 file(s)', $output);
    }

    #[Test]
    public function itFormatsGroupedByFile(): void
    {
        $report = $this->buildMultiFileReport();
        $output = $this->formatter->format($report, $this->plainContext);

        // File headers with finding counts
        self::assertStringContainsString('a.php (2 violations)', $output);
        self::assertStringContainsString('b.php (1 violation)', $output);

        // Non-precise findings don't show line numbers — only symbol names
        self::assertStringContainsString('A2', $output);
        self::assertStringContainsString('A1', $output);
        self::assertStringContainsString('B', $output);
    }

    #[Test]
    public function itFormatsGroupedByRule(): void
    {
        $context = new FormatterContext(useColor: false, groupBy: GroupBy::Rule, isGroupByExplicit: true);
        $report = $this->buildMultiFileReport();
        $output = $this->formatter->format($report, $context);

        // Rule headers with counts
        self::assertStringContainsString('complexity (2)', $output);
        self::assertStringContainsString('lcom (1)', $output);
    }

    #[Test]
    public function itFormatsGroupedBySeverity(): void
    {
        $context = new FormatterContext(useColor: false, groupBy: GroupBy::Severity, isGroupByExplicit: true);
        $report = $this->buildMultiFileReport();
        $output = $this->formatter->format($report, $context);

        // Severity headers
        self::assertStringContainsString('Errors (2)', $output);
        self::assertStringContainsString('Warnings (1)', $output);

        // Errors should appear before warnings
        $posErrors = strpos($output, 'Errors (2)');
        $posWarnings = strpos($output, 'Warnings (1)');
        self::assertLessThan($posWarnings, $posErrors);
    }

    #[Test]
    public function itFormatsFlat(): void
    {
        $context = new FormatterContext(useColor: false, groupBy: GroupBy::None, isGroupByExplicit: true);
        $report = $this->buildMultiFileReport();
        $output = $this->formatter->format($report, $context);

        // No file headers, but full file paths in findings (without line numbers for non-precise)
        self::assertStringNotContainsString('a.php (2', $output);
        self::assertStringContainsString('a.php', $output);
        self::assertStringContainsString('b.php', $output);
    }

    #[Test]
    public function itUsesHumanMessageWhenAvailable(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 42),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic',
                message: 'Cyclomatic complexity is 25, exceeds threshold of 10',
                severity: Severity::Error,
                metricValue: 25,
                recommendation: 'Cyclomatic complexity: 25 (threshold: 10) — too many code paths',
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        // Should use recommendation, not technical message
        self::assertStringContainsString('too many code paths', $output);
    }

    #[Test]
    public function itFallsBackToMessageWhenHumanMessageNull(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 42),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic',
                message: 'Cyclomatic complexity is 25, exceeds threshold of 10',
                severity: Severity::Error,
                metricValue: 25,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        // Falls back to technical message
        self::assertStringContainsString('Cyclomatic complexity is 25, exceeds threshold of 10', $output);
    }

    #[Test]
    public function itOutputsDebtBreakdown(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'doWork'),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic',
                message: 'Cyclomatic complexity is 25',
                severity: Severity::Error,
                metricValue: 25,
            ))
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 20),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'process'),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic',
                message: 'Cyclomatic complexity is 15',
                severity: Severity::Warning,
                metricValue: 15,
            ))
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Bar.php'), 5),
                symbolPath: SymbolPath::forClass('App', 'Bar'),
                ruleName: 'cohesion.lcom',
                code: 'cohesion.lcom',
                message: 'LCOM is 5',
                severity: Severity::Warning,
                metricValue: 5,
            ))
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.05)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('Technical debt by rule:', $output);
        self::assertStringContainsString('complexity.cyclomatic', $output);
        self::assertStringContainsString('2 violations', $output);
        self::assertStringContainsString('cohesion.lcom', $output);
        self::assertStringContainsString('1 violation', $output);
    }

    private function buildMultiFileReport(): Report
    {
        return ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('b.php'), 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'lcom',
                code: 'lcom',
                message: 'LCOM is 5',
                severity: Severity::Warning,
            ))
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('a.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'A1'),
                ruleName: 'complexity',
                code: 'complexity.callable',
                message: 'Too complex',
                severity: Severity::Error,
            ))
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('a.php'), 5),
                symbolPath: SymbolPath::forClass('App', 'A2'),
                ruleName: 'complexity',
                code: 'complexity.class',
                message: 'Class too complex',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.05)
            ->build();
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
