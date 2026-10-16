<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit\Formatter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex;
use Qualimetrix\Reporting\CoverageFailure;
use Qualimetrix\Reporting\Formatter\CoverageNarrator;
use Qualimetrix\Reporting\Report;
use Qualimetrix\Reporting\ReportCoverage;

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
            FileNamespaceIndex::fromRepository(null),
            [],
            0,
            25,
            0.0,
            0,
            0,
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
