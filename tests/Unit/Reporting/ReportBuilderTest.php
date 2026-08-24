<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting;

use ArrayIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\ReportBuilder;

#[CoversClass(ReportBuilder::class)]
final class ReportBuilderTest extends TestCase
{
    #[Test]
    public function itCreatesNewInstance(): void
    {
        $builder = ReportBuilder::create();

        self::assertInstanceOf(ReportBuilder::class, $builder); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    #[Test]
    public function itBuildsEmptyReport(): void
    {
        $report = ReportBuilder::create()->build();

        self::assertTrue($report->isEmpty());
        self::assertSame(0, $report->filesAnalyzed);
        self::assertSame(0, $report->filesSkipped);
        self::assertSame(0.0, $report->duration);
        self::assertSame(0, $report->errorCount);
        self::assertSame(0, $report->warningCount);
    }

    #[Test]
    public function itAddsFinding(): void
    {
        $finding = $this->createFinding(Severity::Error);

        $report = ReportBuilder::create()
            ->addFinding($finding)
            ->build();

        self::assertSame([$finding], $report->findings);
        self::assertSame(1, $report->errorCount);
        self::assertSame(0, $report->warningCount);
    }

    #[Test]
    public function itAddsFindings(): void
    {
        $error = $this->createFinding(Severity::Error);
        $warning = $this->createFinding(Severity::Warning);

        $report = ReportBuilder::create()
            ->addFindings([$error, $warning])
            ->build();

        self::assertCount(2, $report->findings);
        self::assertSame(1, $report->errorCount);
        self::assertSame(1, $report->warningCount);
    }

    #[Test]
    public function itAddsFindingsFromIterator(): void
    {
        $findings = new ArrayIterator([
            $this->createFinding(Severity::Warning),
            $this->createFinding(Severity::Warning),
        ]);

        $report = ReportBuilder::create()
            ->addFindings($findings)
            ->build();

        self::assertCount(2, $report->findings);
        self::assertSame(2, $report->warningCount);
    }

    #[Test]
    public function itSetsFilesAnalyzed(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(42)
            ->build();

        self::assertSame(42, $report->filesAnalyzed);
    }

    #[Test]
    public function itSetsFilesSkipped(): void
    {
        $report = ReportBuilder::create()
            ->filesSkipped(5)
            ->build();

        self::assertSame(5, $report->filesSkipped);
    }

    #[Test]
    public function itSetsDuration(): void
    {
        $report = ReportBuilder::create()
            ->duration(1.234)
            ->build();

        self::assertSame(1.234, $report->duration);
    }

    #[Test]
    public function itSupportsFluentInterface(): void
    {
        $error = $this->createFinding(Severity::Error);
        $warning = $this->createFinding(Severity::Warning);

        $report = ReportBuilder::create()
            ->addFinding($error)
            ->addFinding($warning)
            ->filesAnalyzed(100)
            ->filesSkipped(10)
            ->duration(2.5)
            ->build();

        self::assertCount(2, $report->findings);
        self::assertSame(100, $report->filesAnalyzed);
        self::assertSame(10, $report->filesSkipped);
        self::assertSame(2.5, $report->duration);
        self::assertSame(1, $report->errorCount);
        self::assertSame(1, $report->warningCount);
    }

    #[Test]
    public function itCalculatesViolationCountsCorrectly(): void
    {
        $report = ReportBuilder::create()
            ->addFinding($this->createFinding(Severity::Error))
            ->addFinding($this->createFinding(Severity::Error))
            ->addFinding($this->createFinding(Severity::Error))
            ->addFinding($this->createFinding(Severity::Warning))
            ->addFinding($this->createFinding(Severity::Warning))
            ->build();

        self::assertSame(3, $report->errorCount);
        self::assertSame(2, $report->warningCount);
        self::assertSame(5, $report->getTotalFindings());
    }

    #[Test]
    public function itDefaultsMetricsToNull(): void
    {
        $report = ReportBuilder::create()->build();

        self::assertNull($report->metrics);
    }

    #[Test]
    public function itPassesMetricsThrough(): void
    {
        $metrics = self::createStub(MetricRepositoryInterface::class);

        $report = ReportBuilder::create()
            ->metrics($metrics)
            ->build();

        self::assertSame($metrics, $report->metrics);
    }

    private function createFinding(Severity $severity): Finding
    {
        return self::finding(
            location: new Location(RelativePath::fromString('test.php'), 1),
            symbolPath: SymbolPath::forClass('App', 'Test'),
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
