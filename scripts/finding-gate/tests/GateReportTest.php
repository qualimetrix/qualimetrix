<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;
use QmxFindingGate\GateReport;

/**
 * Every declaration a green run was green under is published, by machine and in the verdict sentence.
 */
final class GateReportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itPublishesEveryDeclarationCountAndNamesTheOnesAGreenRunUsed(): void
    {
        $report = new GateReport();
        $report->countDeclarations('declaredRecordCount', 2);
        $path = Fs::temporaryDirectory('gate-report-test-') . '/report.json';

        try {
            $report->writeJson($path);
            $published = json_decode(Fs::read($path), true, 512, \JSON_THROW_ON_ERROR);
        } finally {
            Fs::removeRecursively(\dirname($path));
        }

        self::assertIsArray($published);

        foreach (array_keys(GateReport::DECLARATION_COUNTS) as $reportKey) {
            self::assertSame($reportKey === 'declaredRecordCount' ? 2 : 0, $published[$reportKey] ?? null, $reportKey);
        }

        self::assertStringContainsString('with 2 declared record(s).', $report->render());
    }

    #[Test]
    public function itRefusesACountNoFormPublishes(): void
    {
        $this->expectException(GateError::class);

        (new GateReport())->countDeclarations('declaredWishCount', 1);
    }
}
