<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Reporting\Formatter;

use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\SymbolPath;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Formatter\TextFormatter;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\ReportBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextFormatter::class)]
final class TextFormatterTest extends TestCase
{
    private TextFormatter $formatter;
    private FormatterContext $plainContext;

    protected function setUp(): void
    {
        $this->formatter = new TextFormatter();
        $this->plainContext = new FormatterContext(useColor: false);
    }

    public function testGetNameReturnsText(): void
    {
        self::assertSame('text', $this->formatter->getName());
    }

    public function testGetDefaultGroupByReturnsNone(): void
    {
        self::assertSame(GroupBy::None, $this->formatter->getDefaultGroupBy());
    }

    public function testFormatEmptyReport(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(42)
            ->filesSkipped(0)
            ->duration(0.15)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertSame("0 error(s), 0 warning(s) in 42 file(s)\n", $output);
    }

    public function testFormatSingleViolation(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php', 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculateDiscount'),
                ruleName: 'cyclomatic-complexity',
                violationCode: 'cyclomatic-complexity',
                message: 'Cyclomatic complexity of 25 exceeds threshold',
                severity: Severity::Error,
                metricValue: 25,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        $lines = explode("\n", rtrim($output, "\n"));

        self::assertCount(3, $lines);
        self::assertSame(
            'src/Service/UserService.php:42: error[cyclomatic-complexity]: Cyclomatic complexity of 25 exceeds threshold (UserService::calculateDiscount)',
            $lines[0],
        );
        self::assertSame('', $lines[1]);
        self::assertSame('1 error(s), 0 warning(s) in 1 file(s)', $lines[2]);
        self::assertStringEndsWith("\n", $output);
    }

    public function testFormatMultipleViolations(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php', 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculateDiscount'),
                ruleName: 'cyclomatic-complexity',
                violationCode: 'cyclomatic-complexity',
                message: 'Cyclomatic complexity of 25 exceeds threshold',
                severity: Severity::Error,
                metricValue: 25,
            ))
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php', 120),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'processOrder'),
                ruleName: 'cyclomatic-complexity',
                violationCode: 'cyclomatic-complexity',
                message: 'Cyclomatic complexity of 12 exceeds threshold',
                severity: Severity::Warning,
                metricValue: 12,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.23)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        $lines = explode("\n", rtrim($output, "\n"));

        self::assertCount(4, $lines);
        self::assertStringStartsWith('src/Service/UserService.php:42: error[cyclomatic-complexity]:', $lines[0]);
        self::assertStringStartsWith('src/Service/UserService.php:120: warning[cyclomatic-complexity]:', $lines[1]);
        self::assertSame('', $lines[2]);
        self::assertSame('1 error(s), 1 warning(s) in 1 file(s)', $lines[3]);
        self::assertStringEndsWith("\n", $output);
    }

    public function testFormatClassLevelViolation(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php', 10),
                symbolPath: SymbolPath::forClass('App\Service', 'UserService'),
                ruleName: 'lcom',
                violationCode: 'lcom',
                message: 'LCOM is 5',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.05)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('warning[lcom]: LCOM is 5 (UserService)', $output);
    }

    public function testFormatNamespaceLevelViolation(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php'),
                symbolPath: SymbolPath::forNamespace('App\Service'),
                ruleName: 'namespace-size',
                violationCode: 'namespace-size',
                message: 'Namespace contains 16 classes',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(10)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('src/Service/UserService.php: error[namespace-size]: Namespace contains 16 classes (namespace: App\Service)', $output);
    }

    public function testFormatFileLevelViolation(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php'),
                symbolPath: SymbolPath::forFile('src/Service/UserService.php'),
                ruleName: 'file-size',
                violationCode: 'file-size',
                message: 'File is too large',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('src/Service/UserService.php: warning[file-size]: File is too large', $output);
    }

    public function testFormatGlobalFunctionViolation(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/functions.php', 5),
                symbolPath: SymbolPath::forGlobalFunction('', 'myComplexFunction'),
                ruleName: 'cyclomatic-complexity',
                violationCode: 'cyclomatic-complexity',
                message: 'Function has complexity of 20',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('src/functions.php:5: warning[cyclomatic-complexity]: Function has complexity of 20 (myComplexFunction)', $output);
    }

    public function testOutputIsParseable(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'test-rule',
                violationCode: 'test-rule',
                message: 'Test message',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);
        $lines = explode("\n", $output);
        $violationLine = $lines[0];

        // Parse using cut-like logic: file:line: severity[rule]: message (symbol)
        preg_match('/^([^:]+):(\d+): (error|warning)\[([^\]]+)\]: (.+)$/', $violationLine, $matches);

        self::assertCount(6, $matches);
        self::assertSame('src/Foo.php', $matches[1]);
        self::assertSame('10', $matches[2]);
        self::assertSame('error', $matches[3]);
        self::assertSame('test-rule', $matches[4]);
        self::assertSame('Test message (Foo::bar)', $matches[5]);
    }

    public function testViolationCodeUsedInBrackets(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'complexity',
                violationCode: 'complexity.method',
                message: 'Too complex',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('[complexity.method]', $output);
        self::assertStringNotContainsString('[complexity]', $output);
    }

    public function testColoredOutputContainsAnsiCodes(): void
    {
        $colorContext = new FormatterContext(useColor: true);

        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Test',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $colorContext);

        // Should contain ANSI escape codes
        self::assertStringContainsString("\e[", $output);
        // Error severity should be red
        self::assertStringContainsString("\e[31merror\e[0m", $output);
    }

    public function testNoAnsiCodesWithColorDisabled(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
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

    public function testSortingBySeverityThenFile(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('b.php', 5),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Warning B',
                severity: Severity::Warning,
            ))
            ->addViolation(new Violation(
                location: new Location('a.php', 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Error A',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        // Default groupBy=None sorts by severity first: error before warning
        $posError = strpos($output, 'Error A');
        $posWarning = strpos($output, 'Warning B');

        self::assertNotFalse($posError);
        self::assertNotFalse($posWarning);
        self::assertLessThan($posWarning, $posError);
    }

    public function testSummaryColoredRedForErrors(): void
    {
        $colorContext = new FormatterContext(useColor: true);

        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('a.php', 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Msg',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $colorContext);

        // Summary should be bold red when errors present
        self::assertStringContainsString("\e[1;31m1 error(s)", $output);
    }

    public function testSummaryColoredGreenForNoViolations(): void
    {
        $colorContext = new FormatterContext(useColor: true);

        $report = ReportBuilder::create()
            ->filesAnalyzed(5)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $colorContext);

        // Summary should be bold green when no violations
        self::assertStringContainsString("\e[1;32m0 error(s)", $output);
    }
}
