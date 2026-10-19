<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit\Formatter\Text;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex;
use Qualimetrix\Reporting\Contract\Report\Report;
use Qualimetrix\Reporting\Coverage\CoverageFailure;
use Qualimetrix\Reporting\Coverage\ReportCoverage;
use Qualimetrix\Reporting\Formatter\Text\CoverageNarrator;

#[CoversClass(CoverageNarrator::class)]
final class CoverageNarratorTest extends TestCase
{
    #[Test]
    public function itNamesTheFirstTenFailedPathsInReportOrderAndCountsTheRest(): void
    {
        $failures = [];
        for ($number = 1; $number <= 25; ++$number) {
            $failures[] = new CoverageFailure('src/Broken' . $number . '.php', 'parse', 'Syntax error');
        }
        $report = new Report(
            fileNamespaces: FileNamespaceIndex::fromRepository(null),
            findings: [],
            filesAnalyzed: 0,
            filesSkipped: 25,
            duration: 0.0,
            coverage: new ReportCoverage(25, 0, 0, 25, $failures),
        );

        $lines = CoverageNarrator::lines($report);

        self::assertCount(12, $lines);
        self::assertStringContainsString('25 of 25', $lines[0]);
        for ($number = 1; $number <= 10; ++$number) {
            self::assertSame('Failed file: src/Broken' . $number . '.php', $lines[$number]);
        }
        self::assertSame('and 15 more failed files.', $lines[11]);
    }
}
