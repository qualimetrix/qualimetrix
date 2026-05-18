<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting;

use ArrayIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
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
    public function itAddsViolation(): void
    {
        $violation = $this->createViolation(Severity::Error);

        $report = ReportBuilder::create()
            ->addViolation($violation)
            ->build();

        self::assertSame([$violation], $report->violations);
        self::assertSame(1, $report->errorCount);
        self::assertSame(0, $report->warningCount);
    }

    #[Test]
    public function itAddsViolations(): void
    {
        $error = $this->createViolation(Severity::Error);
        $warning = $this->createViolation(Severity::Warning);

        $report = ReportBuilder::create()
            ->addViolations([$error, $warning])
            ->build();

        self::assertCount(2, $report->violations);
        self::assertSame(1, $report->errorCount);
        self::assertSame(1, $report->warningCount);
    }

    #[Test]
    public function itAddsViolationsFromIterator(): void
    {
        $violations = new ArrayIterator([
            $this->createViolation(Severity::Warning),
            $this->createViolation(Severity::Warning),
        ]);

        $report = ReportBuilder::create()
            ->addViolations($violations)
            ->build();

        self::assertCount(2, $report->violations);
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
        $error = $this->createViolation(Severity::Error);
        $warning = $this->createViolation(Severity::Warning);

        $report = ReportBuilder::create()
            ->addViolation($error)
            ->addViolation($warning)
            ->filesAnalyzed(100)
            ->filesSkipped(10)
            ->duration(2.5)
            ->build();

        self::assertCount(2, $report->violations);
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
            ->addViolation($this->createViolation(Severity::Error))
            ->addViolation($this->createViolation(Severity::Error))
            ->addViolation($this->createViolation(Severity::Error))
            ->addViolation($this->createViolation(Severity::Warning))
            ->addViolation($this->createViolation(Severity::Warning))
            ->build();

        self::assertSame(3, $report->errorCount);
        self::assertSame(2, $report->warningCount);
        self::assertSame(5, $report->getTotalViolations());
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

    private function createViolation(Severity $severity): Violation
    {
        return new Violation(
            location: new Location(RelativePath::fromString('test.php'), 1),
            symbolPath: SymbolPath::forClass('App', 'Test'),
            ruleName: 'test-rule',
            violationCode: 'test-rule',
            message: 'Test message',
            severity: $severity,
        );
    }
}
