<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Debt\DebtCalculator;
use Qualimetrix\Reporting\Debt\RemediationTimeRegistry;
use Qualimetrix\Reporting\Formatter\Support\DetailedViolationRenderer;
use Qualimetrix\Reporting\Formatter\TextFormatter;
use Qualimetrix\Reporting\Formatter\TextVerboseFormatter;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Report;
use Qualimetrix\Reporting\ReportBuilder;

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

        // Non-precise violations don't show line numbers — only symbol names
        self::assertStringContainsString('A2', $output);
        self::assertStringContainsString('A1', $output);
        self::assertStringContainsString('B', $output);
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

        // No file headers, but full file paths in violations (without line numbers for non-precise)
        self::assertStringNotContainsString('a.php (2', $output);
        self::assertStringContainsString('a.php', $output);
        self::assertStringContainsString('b.php', $output);
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
