<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Report;

#[CoversClass(Report::class)]
final class ReportTest extends TestCase
{
    #[Test]
    public function itIsEmptyWhenNoViolations(): void
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

    #[Test]
    public function itIsNotEmptyWhenHasViolations(): void
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

    #[Test]
    public function itCountsTotalViolations(): void
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

    #[Test]
    public function itFiltersViolationsBySeverity(): void
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

    #[Test]
    public function itReturnsZeroExitCodeForEmptyReport(): void
    {
        $report = new Report([], 10, 0, 0.5, 0, 0);

        self::assertSame(0, $report->getExitCode());
    }

    #[Test]
    public function itReturnsOneExitCodeForWarningsOnly(): void
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

    #[Test]
    public function itReturnsTwoExitCodeForErrors(): void
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

    #[Test]
    public function itExposesReportProperties(): void
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
        return self::violation(
            location: new Location(RelativePath::fromString('test.php'), 1),
            symbolPath: SymbolPath::forClass('App', $name),
            ruleName: 'test-rule',
            violationCode: 'test-rule',
            message: 'Test message',
            severity: $severity,
        );
    }

    /** @param list<\Qualimetrix\Analysis\Finding\Contract\Location> $relatedLocations */
    private static function violation(\Qualimetrix\Analysis\Finding\Contract\Location $location, \Qualimetrix\Core\Symbol\SymbolPath $symbolPath, string $ruleName, string $violationCode, string $message, \Qualimetrix\Analysis\Finding\Contract\Severity $severity, int|float|null $metricValue = null, ?\Qualimetrix\Analysis\Finding\Contract\Rule\RuleLevel $level = null, array $relatedLocations = [], ?string $recommendation = null, int|float|null $threshold = null, ?\Qualimetrix\Core\Symbol\SymbolPath $dependencyTarget = null, ?\Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType $dependencyType = null, ?\Qualimetrix\Analysis\Finding\Contract\AcceptedLevel $acceptedLevel = null, ?\Qualimetrix\Analysis\Finding\Contract\OccurrenceKey $occurrenceKey = null, ?\Qualimetrix\Core\Symbol\MetricSubject $subject = null): Violation
    {
        $subject ??= match ($symbolPath->getType()) {
            \Qualimetrix\Core\Symbol\SymbolType::File, \Qualimetrix\Core\Symbol\SymbolType::Namespace_, \Qualimetrix\Core\Symbol\SymbolType::Project => \Qualimetrix\Core\Symbol\MetricSubject::aggregate($symbolPath),
            default => \Qualimetrix\Core\Symbol\MetricSubject::declaration(new \Qualimetrix\Core\Symbol\DeclarationPath($symbolPath, $location->file ?? \Qualimetrix\Core\Path\RelativePath::fromString('tests/Reporting/fixture.php'), $location->line ?? 0)),
        };
        return new Violation(location: $location, subject: $subject, symbolPath: $symbolPath, ruleName: $ruleName, violationCode: $violationCode, message: $message, severity: $severity, metricValue: $metricValue, level: $level, relatedLocations: $relatedLocations, recommendation: $recommendation, threshold: $threshold, dependencyTarget: $dependencyTarget, dependencyType: $dependencyType, acceptedLevel: $acceptedLevel, occurrenceKey: $occurrenceKey);
    }

}
