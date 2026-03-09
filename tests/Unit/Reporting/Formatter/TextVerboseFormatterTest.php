<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Reporting\Formatter;

use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Debt\DebtCalculator;
use AiMessDetector\Reporting\Debt\RemediationTimeRegistry;
use AiMessDetector\Reporting\Formatter\TextVerboseFormatter;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\Report;
use AiMessDetector\Reporting\ReportBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextVerboseFormatter::class)]
final class TextVerboseFormatterTest extends TestCase
{
    private TextVerboseFormatter $formatter;
    private FormatterContext $plainContext;

    protected function setUp(): void
    {
        $this->formatter = new TextVerboseFormatter(new DebtCalculator(new RemediationTimeRegistry()));
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

    public function testFormatEmptyReport(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(42)
            ->filesSkipped(0)
            ->duration(0.15)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('AI Mess Detector Report', $output);
        self::assertStringContainsString('No violations found.', $output);
        self::assertStringContainsString('Files: 42 analyzed, 0 skipped | Errors: 0 | Warnings: 0 | Time: 0.15s', $output);
        self::assertStringContainsString('Technical debt: 0min', $output);
    }

    public function testFormatGroupedByFile(): void
    {
        $report = $this->buildMultiFileReport();
        $output = $this->formatter->format($report, $this->plainContext);

        // File headers with counts
        self::assertStringContainsString('a.php (2)', $output);
        self::assertStringContainsString('b.php (1)', $output);

        // Violations within groups show line-only location
        self::assertStringContainsString(':5', $output);
        self::assertStringContainsString(':10', $output);
        self::assertStringContainsString(':20', $output);
    }

    public function testFormatGroupedByRule(): void
    {
        $context = new FormatterContext(useColor: false, groupBy: GroupBy::Rule);
        $report = $this->buildMultiFileReport();
        $output = $this->formatter->format($report, $context);

        // Rule headers with counts
        self::assertStringContainsString('complexity (2)', $output);
        self::assertStringContainsString('lcom (1)', $output);
    }

    public function testFormatGroupedBySeverity(): void
    {
        $context = new FormatterContext(useColor: false, groupBy: GroupBy::Severity);
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
        $context = new FormatterContext(useColor: false, groupBy: GroupBy::None);
        $report = $this->buildMultiFileReport();
        $output = $this->formatter->format($report, $context);

        // No file headers, but full file paths in violations
        self::assertStringNotContainsString('a.php (2)', $output);
        self::assertStringContainsString('a.php:5', $output);
        self::assertStringContainsString('b.php:20', $output);
    }

    public function testCompactViolationFormat(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Foo.php', 42),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'complexity',
                violationCode: 'complexity.method',
                message: 'Cyclomatic complexity of 25 exceeds threshold',
                severity: Severity::Error,
                metricValue: 25,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        // Severity tag
        self::assertStringContainsString('ERROR', $output);
        // Violation code in brackets
        self::assertStringContainsString('[complexity.method]', $output);
        // Message
        self::assertStringContainsString('Cyclomatic complexity of 25 exceeds threshold', $output);
        // Metric value
        self::assertStringContainsString('(25)', $output);
        // Symbol
        self::assertStringContainsString('App\Foo::bar', $output);
    }

    public function testMetricValueNotShownWhenNull(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Something wrong',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        // No metric value parentheses (only the violation code brackets)
        self::assertStringNotContainsString('(null)', $output);
    }

    public function testColoredOutput(): void
    {
        $colorContext = new FormatterContext(useColor: true, groupBy: GroupBy::File);

        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Error msg',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $colorContext);

        // Contains ANSI codes
        self::assertStringContainsString("\e[", $output);
        // Bold red ERROR
        self::assertStringContainsString("\e[1;31mERROR\e[0m", $output);
    }

    public function testNoAnsiCodesWithColorDisabled(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Test',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringNotContainsString("\e[", $output);
    }

    public function testSummaryColorReflectsViolations(): void
    {
        $colorContext = new FormatterContext(useColor: true, groupBy: GroupBy::File);

        // Report with errors => red summary
        $errorReport = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('a.php', 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Err',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($errorReport, $colorContext);
        self::assertStringContainsString("\e[1;31mFiles:", $output);

        // Report with only warnings => yellow summary
        $warnReport = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('a.php', 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Warn',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($warnReport, $colorContext);
        self::assertStringContainsString("\e[1;33mFiles:", $output);

        // Empty report => green summary
        $emptyReport = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($emptyReport, $colorContext);
        self::assertStringContainsString("\e[1;32m", $output);
    }

    public function testNamespaceLevelViolation(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php'),
                symbolPath: SymbolPath::forNamespace('App\Service'),
                ruleName: 'namespace-size',
                violationCode: 'namespace-size',
                message: 'Namespace contains 16 classes (threshold: 10)',
                severity: Severity::Error,
                metricValue: 16,
            ))
            ->filesAnalyzed(10)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('ERROR', $output);
        self::assertStringContainsString('App\Service', $output);
        self::assertStringContainsString('[namespace-size]', $output);
    }

    public function testRelativizesAbsolutePathsWithBasePath(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('/home/user/project/src/Service/UserService.php', 42),
                symbolPath: SymbolPath::forMethod('App\\Service', 'UserService', 'calculate'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Test',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $context = new FormatterContext(useColor: false, groupBy: GroupBy::None, basePath: '/home/user/project');
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('src/Service/UserService.php:42', $output);
        self::assertStringNotContainsString('/home/user/project/', $output);
    }

    public function testRelativizesFileGroupHeaders(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('/home/user/project/src/Foo.php', 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Test',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $context = new FormatterContext(useColor: false, groupBy: GroupBy::File, basePath: '/home/user/project');
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('src/Foo.php (1)', $output);
        self::assertStringNotContainsString('/home/user/project/', $output);
    }

    public function testOutputContainsHeader(): void
    {
        $report = new Report([], 0, 0, 0.0, 0, 0);
        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('AI Mess Detector Report', $output);
        self::assertStringContainsString('Technical debt: 0min', $output);
    }

    public function testSortingWithinFileGroup(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('a.php', 30),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Line 30',
                severity: Severity::Warning,
            ))
            ->addViolation(new Violation(
                location: new Location('a.php', 5),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Line 5',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        // Within file group: sorted by severity then line
        $posError = strpos($output, 'Line 5');
        $posWarning = strpos($output, 'Line 30');

        self::assertNotFalse($posError);
        self::assertNotFalse($posWarning);
        self::assertLessThan($posWarning, $posError);
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

        // Total debt: 2 × 30min (cyclomatic) + 1 × 45min (lcom) = 105min = 1h 45min
        self::assertStringContainsString('Technical debt: 1h 45min', $output);

        // Per-rule breakdown sorted by debt descending
        // complexity.cyclomatic: 60min (2 violations × 30min)
        self::assertStringContainsString('complexity.cyclomatic: 1h (2 violations', $output);
        // design.lcom: 45min (1 violation × 45min)
        self::assertStringContainsString('design.lcom: 45min (1 violation', $output);

        // complexity.cyclomatic should appear before design.lcom (60 > 45)
        $posCyclomatic = strpos($output, 'complexity.cyclomatic:');
        $posLcom = strpos($output, 'design.lcom:');
        self::assertNotFalse($posCyclomatic);
        self::assertNotFalse($posLcom);
        self::assertLessThan($posLcom, $posCyclomatic);
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
