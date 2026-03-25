<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Report;

#[CoversClass(Report::class)]
final class ReportTest extends TestCase
{
    public function testEmptyReportIsEmpty(): void
    {
        $report = new Report(
            violations: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 0.5,
            errorCount: 0,
            warningCount: 0,
        );

        self::assertTrue($report->isEmpty());
        self::assertSame(0, $report->getTotalViolations());
    }

    public function testReportWithViolationsIsNotEmpty(): void
    {
        $violation = $this->createViolation(Severity::Error);

        $report = new Report(
            violations: [$violation],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 0.5,
            errorCount: 1,
            warningCount: 0,
        );

        self::assertFalse($report->isEmpty());
        self::assertSame(1, $report->getTotalViolations());
    }

    public function testGetTotalViolations(): void
    {
        $violations = [
            $this->createViolation(Severity::Error),
            $this->createViolation(Severity::Warning),
            $this->createViolation(Severity::Error),
        ];

        $report = new Report(
            violations: $violations,
            filesAnalyzed: 5,
            filesSkipped: 1,
            duration: 0.3,
            errorCount: 2,
            warningCount: 1,
        );

        self::assertSame(3, $report->getTotalViolations());
    }

    public function testGetViolationsBySeverity(): void
    {
        $error1 = $this->createViolation(Severity::Error, 'error1');
        $error2 = $this->createViolation(Severity::Error, 'error2');
        $warning = $this->createViolation(Severity::Warning, 'warning1');

        $report = new Report(
            violations: [$error1, $warning, $error2],
            filesAnalyzed: 3,
            filesSkipped: 0,
            duration: 0.2,
            errorCount: 2,
            warningCount: 1,
        );

        $errors = $report->getViolationsBySeverity(Severity::Error);
        $warnings = $report->getViolationsBySeverity(Severity::Warning);

        self::assertCount(2, $errors);
        self::assertCount(1, $warnings);
        self::assertSame($error1, $errors[0]);
        self::assertSame($error2, $errors[1]);
        self::assertSame($warning, $warnings[0]);
    }

    public function testGetExitCodeReturnsZeroForEmptyReport(): void
    {
        $report = new Report([], 10, 0, 0.5, 0, 0);

        self::assertSame(0, $report->getExitCode());
    }

    public function testGetExitCodeReturnsOneForWarningsOnly(): void
    {
        $report = new Report(
            violations: [$this->createViolation(Severity::Warning)],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 0.5,
            errorCount: 0,
            warningCount: 1,
        );

        self::assertSame(1, $report->getExitCode());
    }

    public function testGetExitCodeReturnsTwoForErrors(): void
    {
        $report = new Report(
            violations: [
                $this->createViolation(Severity::Warning),
                $this->createViolation(Severity::Error),
            ],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 0.5,
            errorCount: 1,
            warningCount: 1,
        );

        self::assertSame(2, $report->getExitCode());
    }

    public function testReportProperties(): void
    {
        $violations = [$this->createViolation(Severity::Error)];

        $report = new Report(
            violations: $violations,
            filesAnalyzed: 42,
            filesSkipped: 3,
            duration: 1.23,
            errorCount: 1,
            warningCount: 0,
        );

        self::assertSame($violations, $report->violations);
        self::assertSame(42, $report->filesAnalyzed);
        self::assertSame(3, $report->filesSkipped);
        self::assertSame(1.23, $report->duration);
        self::assertSame(1, $report->errorCount);
        self::assertSame(0, $report->warningCount);
    }

    private function createViolation(Severity $severity, string $name = 'test'): Violation
    {
        return new Violation(
            location: new Location('test.php', 1),
            symbolPath: SymbolPath::forClass('App', $name),
            ruleName: 'test-rule',
            violationCode: 'test-rule',
            message: 'Test message',
            severity: $severity,
        );
    }
}
