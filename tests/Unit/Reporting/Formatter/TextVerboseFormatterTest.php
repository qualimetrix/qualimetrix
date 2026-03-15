<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Reporting\Formatter;

use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Debt\DebtCalculator;
use AiMessDetector\Reporting\Debt\RemediationTimeRegistry;
use AiMessDetector\Reporting\DetailedViolationRenderer;
use AiMessDetector\Reporting\Formatter\TextFormatter;
use AiMessDetector\Reporting\Formatter\TextVerboseFormatter;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\Report;
use AiMessDetector\Reporting\ReportBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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
        $debtCalculator = new DebtCalculator(new RemediationTimeRegistry());
        $detailedRenderer = new DetailedViolationRenderer($debtCalculator);
        $this->textFormatter = new TextFormatter($debtCalculator, $detailedRenderer);
        $this->formatter = new TextVerboseFormatter($this->textFormatter);
        $this->plainContext = new FormatterContext(useColor: false, groupBy: GroupBy::File);
    }

    public function testGetNameReturnsTextVerbose(): void
    {
        self::assertSame('text-verbose', $this->formatter->getName());
    }

    public function testGetDefaultGroupByReturnsFile(): void
    {
        self::assertSame(GroupBy::File, $this->formatter->getDefaultGroupBy());
    }

    public function testDelegatesToTextFormatterWithDetailEnabled(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Foo.php', 42),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
                message: 'Cyclomatic complexity is 15',
                severity: Severity::Error,
                metricValue: 15,
                humanMessage: 'Cyclomatic complexity: 15 (max 10) — too many code paths',
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $context = new FormatterContext(useColor: false, groupBy: GroupBy::File);
        $verboseOutput = $this->formatter->format($report, $context);

        // Should produce detail-mode output (same as TextFormatter --detail)
        $detailContext = new FormatterContext(useColor: false, groupBy: GroupBy::File, detail: true);
        $detailOutput = $this->textFormatter->format($report, $detailContext);

        self::assertSame($detailOutput, $verboseOutput);
    }

    public function testFormatEmptyReport(): void
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

    public function testFormatGroupedByFile(): void
    {
        $report = $this->buildMultiFileReport();
        $output = $this->formatter->format($report, $this->plainContext);

        // File headers with violation counts
        self::assertStringContainsString('a.php (2 violations)', $output);
        self::assertStringContainsString('b.php (1 violation)', $output);

        // Violations within groups show line-only location
        self::assertStringContainsString(':5', $output);
        self::assertStringContainsString(':10', $output);
        self::assertStringContainsString(':20', $output);
    }

    public function testFormatGroupedByRule(): void
    {
        $context = new FormatterContext(useColor: false, groupBy: GroupBy::Rule, isGroupByExplicit: true);
        $report = $this->buildMultiFileReport();
        $output = $this->formatter->format($report, $context);

        // Rule headers with counts
        self::assertStringContainsString('complexity (2)', $output);
        self::assertStringContainsString('lcom (1)', $output);
    }

    public function testFormatGroupedBySeverity(): void
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

    public function testFormatFlat(): void
    {
        $context = new FormatterContext(useColor: false, groupBy: GroupBy::None, isGroupByExplicit: true);
        $report = $this->buildMultiFileReport();
        $output = $this->formatter->format($report, $context);

        // No file headers, but full file paths in violations
        self::assertStringNotContainsString('a.php (2', $output);
        self::assertStringContainsString('a.php:5', $output);
        self::assertStringContainsString('b.php:20', $output);
    }

    public function testUsesHumanMessageWhenAvailable(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Foo.php', 42),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
                message: 'Cyclomatic complexity is 25, exceeds threshold of 10',
                severity: Severity::Error,
                metricValue: 25,
                humanMessage: 'Cyclomatic complexity: 25 (max 10) — too many code paths',
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        // Should use humanMessage, not technical message
        self::assertStringContainsString('too many code paths', $output);
    }

    public function testFallsBackToMessageWhenHumanMessageNull(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Foo.php', 42),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
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

    public function testDebtBreakdownOutput(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'doWork'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic',
                message: 'Cyclomatic complexity is 25',
                severity: Severity::Error,
                metricValue: 25,
            ))
            ->addViolation(new Violation(
                location: new Location('src/Foo.php', 20),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'process'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic',
                message: 'Cyclomatic complexity is 15',
                severity: Severity::Warning,
                metricValue: 15,
            ))
            ->addViolation(new Violation(
                location: new Location('src/Bar.php', 5),
                symbolPath: SymbolPath::forClass('App', 'Bar'),
                ruleName: 'design.lcom',
                violationCode: 'design.lcom',
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
        self::assertStringContainsString('design.lcom', $output);
        self::assertStringContainsString('1 violation', $output);
    }

    private function buildMultiFileReport(): Report
    {
        return ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('b.php', 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'lcom',
                violationCode: 'lcom',
                message: 'LCOM is 5',
                severity: Severity::Warning,
            ))
            ->addViolation(new Violation(
                location: new Location('a.php', 10),
                symbolPath: SymbolPath::forClass('App', 'A1'),
                ruleName: 'complexity',
                violationCode: 'complexity.method',
                message: 'Too complex',
                severity: Severity::Error,
            ))
            ->addViolation(new Violation(
                location: new Location('a.php', 5),
                symbolPath: SymbolPath::forClass('App', 'A2'),
                ruleName: 'complexity',
                violationCode: 'complexity.class',
                message: 'Class too complex',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.05)
            ->build();
    }
}
