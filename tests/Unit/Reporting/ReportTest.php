<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Report;

#[CoversClass(Report::class)]
final class ReportTest extends TestCase
{
    #[Test]
    public function itIsEmptyWhenNoFindings(): void
    {
        $report = new Report(
            findings: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 0.5,
            errorCount: 0,
            warningCount: 0,
        );

        self::assertTrue($report->isEmpty());
        self::assertSame(0, $report->getTotalFindings());
    }

    #[Test]
    public function itIsNotEmptyWhenHasFindings(): void
    {
        $finding = $this->createFinding(Severity::Error);

        $report = new Report(
            findings: [$finding],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 0.5,
            errorCount: 1,
            warningCount: 0,
        );

        self::assertFalse($report->isEmpty());
        self::assertSame(1, $report->getTotalFindings());
    }

    #[Test]
    public function itCountsTotalFindings(): void
    {
        $findings = [
            $this->createFinding(Severity::Error),
            $this->createFinding(Severity::Warning),
            $this->createFinding(Severity::Error),
        ];

        $report = new Report(
            findings: $findings,
            filesAnalyzed: 5,
            filesSkipped: 1,
            duration: 0.3,
            errorCount: 2,
            warningCount: 1,
        );

        self::assertSame(3, $report->getTotalFindings());
    }

    #[Test]
    public function itFiltersFindingsBySeverity(): void
    {
        $error1 = $this->createFinding(Severity::Error, 'error1');
        $error2 = $this->createFinding(Severity::Error, 'error2');
        $warning = $this->createFinding(Severity::Warning, 'warning1');

        $report = new Report(
            findings: [$error1, $warning, $error2],
            filesAnalyzed: 3,
            filesSkipped: 0,
            duration: 0.2,
            errorCount: 2,
            warningCount: 1,
        );

        $errors = $report->getFindingsBySeverity(Severity::Error);
        $warnings = $report->getFindingsBySeverity(Severity::Warning);

        self::assertCount(2, $errors);
        self::assertCount(1, $warnings);
        self::assertSame($error1, $errors[0]);
        self::assertSame($error2, $errors[1]);
        self::assertSame($warning, $warnings[0]);
    }

    #[Test]
    public function itExposesReportProperties(): void
    {
        $findings = [$this->createFinding(Severity::Error)];

        $report = new Report(
            findings: $findings,
            filesAnalyzed: 42,
            filesSkipped: 3,
            duration: 1.23,
            errorCount: 1,
            warningCount: 0,
        );

        self::assertSame($findings, $report->findings);
        self::assertSame(42, $report->filesAnalyzed);
        self::assertSame(3, $report->filesSkipped);
        self::assertSame(1.23, $report->duration);
        self::assertSame(1, $report->errorCount);
        self::assertSame(0, $report->warningCount);
    }

    private function createFinding(Severity $severity, string $name = 'test'): Finding
    {
        return self::finding(
            location: new Location(RelativePath::fromString('test.php'), 1),
            symbolPath: SymbolPath::forClass('App', $name),
            ruleName: 'test-rule',
            code: 'test-rule',
            message: 'Test message',
            severity: $severity,
        );
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
