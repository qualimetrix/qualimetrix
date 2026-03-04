<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Reporting\Formatter;

use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\SymbolPath;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Formatter\TextVerboseFormatter;
use AiMessDetector\Reporting\Report;
use AiMessDetector\Reporting\ReportBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextVerboseFormatter::class)]
final class TextVerboseFormatterTest extends TestCase
{
    private TextVerboseFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new TextVerboseFormatter();
    }

    public function testGetNameReturnsTextVerbose(): void
    {
        self::assertSame('text-verbose', $this->formatter->getName());
    }

    public function testFormatEmptyReport(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(42)
            ->filesSkipped(0)
            ->duration(0.15)
            ->build();

        $output = $this->formatter->format($report);

        $expected = <<<'TEXT'
AI Mess Detector Report
==================================================

No violations found.

--------------------------------------------------
Files: 42 analyzed, 0 skipped | Errors: 0 | Warnings: 0 | Time: 0.15s

TEXT;

        self::assertSame($expected, $output);
        self::assertStringEndsWith("\n", $output);
    }

    public function testFormatReportWithViolationsSortedBySeverity(): void
    {
        // Add warning first, then error - should be sorted: error first
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php', 120),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'processOrder'),
                ruleName: 'cyclomatic-complexity',
                message: 'Cyclomatic complexity of 12 exceeds threshold',
                severity: Severity::Warning,
                metricValue: 12,
            ))
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php', 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculateDiscount'),
                ruleName: 'cyclomatic-complexity',
                message: 'Cyclomatic complexity of 25 exceeds threshold',
                severity: Severity::Error,
                metricValue: 25,
            ))
            ->filesAnalyzed(42)
            ->filesSkipped(1)
            ->duration(0.23)
            ->build();

        $output = $this->formatter->format($report);

        // Error should appear before warning
        $expected = <<<'TEXT'
AI Mess Detector Report
==================================================

Violations:

  [ERROR] src/Service/UserService.php:42
    App\Service\UserService::calculateDiscount
    Rule: cyclomatic-complexity
    Cyclomatic complexity of 25 exceeds threshold

  [WARNING] src/Service/UserService.php:120
    App\Service\UserService::processOrder
    Rule: cyclomatic-complexity
    Cyclomatic complexity of 12 exceeds threshold

--------------------------------------------------
Files: 42 analyzed, 1 skipped | Errors: 1 | Warnings: 1 | Time: 0.23s

TEXT;

        self::assertSame($expected, $output);
        self::assertStringEndsWith("\n", $output);
    }

    public function testFormatNamespaceLevelViolation(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php'),
                symbolPath: SymbolPath::forNamespace('App\Service'),
                ruleName: 'namespace-size',
                message: 'Namespace contains 16 classes (threshold: 10)',
                severity: Severity::Error,
                metricValue: 16,
            ))
            ->filesAnalyzed(10)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report);

        self::assertStringContainsString('[ERROR] src/Service/UserService.php', $output);
        self::assertStringContainsString('App\Service', $output);
        self::assertStringContainsString('Rule: namespace-size', $output);
        self::assertStringContainsString('Namespace contains 16 classes', $output);
    }

    public function testFormatGlobalFunctionViolation(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/functions.php', 5),
                symbolPath: SymbolPath::forGlobalFunction('', 'myComplexFunction'),
                ruleName: 'cyclomatic-complexity',
                message: 'Function has complexity of 20',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report);

        self::assertStringContainsString('[WARNING] src/functions.php:5', $output);
        self::assertStringContainsString('::myComplexFunction', $output);
    }

    public function testSortingByFileThenLine(): void
    {
        // Same severity, different files/lines - should be sorted by file then line
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('b.php', 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'test',
                message: 'Msg B20',
                severity: Severity::Error,
            ))
            ->addViolation(new Violation(
                location: new Location('a.php', 10),
                symbolPath: SymbolPath::forClass('App', 'A1'),
                ruleName: 'test',
                message: 'Msg A10',
                severity: Severity::Error,
            ))
            ->addViolation(new Violation(
                location: new Location('a.php', 5),
                symbolPath: SymbolPath::forClass('App', 'A2'),
                ruleName: 'test',
                message: 'Msg A5',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.05)
            ->build();

        $output = $this->formatter->format($report);

        // Should be: a.php:5, a.php:10, b.php:20
        $posA5 = strpos($output, 'Msg A5');
        $posA10 = strpos($output, 'Msg A10');
        $posB20 = strpos($output, 'Msg B20');

        self::assertNotFalse($posA5);
        self::assertNotFalse($posA10);
        self::assertNotFalse($posB20);
        self::assertLessThan($posA10, $posA5, 'a.php:5 should come before a.php:10');
        self::assertLessThan($posB20, $posA10, 'a.php:10 should come before b.php:20');
    }

    public function testFormatMultipleErrorsAndWarnings(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('c.php', 3),
                symbolPath: SymbolPath::forClass('App', 'C'),
                ruleName: 'test',
                message: 'Warning 1',
                severity: Severity::Warning,
            ))
            ->addViolation(new Violation(
                location: new Location('a.php', 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                message: 'Error 1',
                severity: Severity::Error,
            ))
            ->addViolation(new Violation(
                location: new Location('b.php', 2),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'test',
                message: 'Error 2',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(3)
            ->filesSkipped(0)
            ->duration(0.05)
            ->build();

        $output = $this->formatter->format($report);

        self::assertStringContainsString('Errors: 2', $output);
        self::assertStringContainsString('Warnings: 1', $output);

        // Errors should come before warnings
        $posError1 = strpos($output, 'Error 1');
        $posError2 = strpos($output, 'Error 2');
        $posWarning1 = strpos($output, 'Warning 1');

        self::assertNotFalse($posError1);
        self::assertNotFalse($posError2);
        self::assertNotFalse($posWarning1);
        self::assertLessThan($posWarning1, $posError1);
        self::assertLessThan($posWarning1, $posError2);
    }

    public function testOutputContainsHeader(): void
    {
        $report = new Report([], 0, 0, 0.0, 0, 0);
        $output = $this->formatter->format($report);

        self::assertStringContainsString('AI Mess Detector Report', $output);
        self::assertStringContainsString('==================================================', $output);
    }
}
