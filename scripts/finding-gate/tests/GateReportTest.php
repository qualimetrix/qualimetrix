<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\FailureClass;
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;
use QmxFindingGate\GateReport;

/**
 * Every declaration a green run was green under is published, by machine and in the verdict sentence,
 * and the verdict a report reaches maps to its own exit code.
 */
final class GateReportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itPublishesFailureWitnessesWithoutSourceOrCallerIdentity(): void
    {
        $report = new GateReport();
        $report->fail('surface-mismatch', 'candidate / case:alpha|format:text', 'The whole invocation differs.', ['- before']);
        self::assertSame([['class' => 'surface-mismatch', 'scope' => 'candidate / case:alpha|format:text', 'detail' => 'The whole invocation differs.']], $report->raised());
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
    public function itPublishesRedDiagnosticsForInvalidSourceBytesWithoutChangingTheComparison(): void
    {
        $report = new GateReport();
        $detail = 'The reference contains K' . \chr(255) . '.';
        $report->fail('surface-mismatch', 'case:bytes|format:json', $detail, ['- K' . \chr(254)]);
        $path = Fs::temporaryDirectory('gate-report-byte-test-') . '/report.json';

        try {
            $report->writeJson($path);
            $published = json_decode(Fs::read($path), true, 512, \JSON_THROW_ON_ERROR);
        } finally {
            Fs::removeRecursively(\dirname($path));
        }

        self::assertIsArray($published);
        self::assertFalse($published['green']);
        self::assertSame(1, $published['exitCode']);
        self::assertSame(['surface-mismatch'], $published['failureClasses']);
        self::assertSame('The reference contains K�.', $published['failures'][0]['detail']);
        self::assertSame(['- K�'], $published['failures'][0]['diff']);
        self::assertStringContainsString($detail, $report->render());
        self::assertStringContainsString('- K' . \chr(254), $report->render());
    }

    #[Test]
    public function itIsGreenAndExitsZeroWithoutAFailureOrALimitation(): void
    {
        $report = new GateReport();

        self::assertSame(GateReport::VERDICT_GREEN, $report->verdict());
        self::assertSame(GateReport::EXIT_GREEN, $report->exitCode());
    }

    #[Test]
    public function itIsPartialWithItsOwnExitCodeAfterALimitationAndDoesNotClaimFullEquivalence(): void
    {
        $report = new GateReport();
        $report->limit('the corpus was restricted');

        self::assertSame(GateReport::VERDICT_PARTIAL, $report->verdict());
        self::assertSame(GateReport::EXIT_PARTIAL, $report->exitCode());
        self::assertSame(2, $report->exitCode());
        self::assertStringContainsString('PARTIAL', $report->render());
        self::assertStringNotContainsString('finding-equivalent under the declared maps', $report->render());
    }

    #[Test]
    public function itLetsAFailureOutrankALimitation(): void
    {
        $report = new GateReport();
        $report->limit('the corpus was restricted');
        $report->fail(FailureClass::RUN_FAILED, 'scope', 'detail');

        self::assertSame(GateReport::VERDICT_RED, $report->verdict());
        self::assertSame(GateReport::EXIT_RED, $report->exitCode());
    }

    #[Test]
    public function itRefusesACountNoFormPublishes(): void
    {
        $this->expectException(GateError::class);

        (new GateReport())->countDeclarations('declaredWishCount', 1);
    }
}
