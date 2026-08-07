<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Baseline;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\Baseline;
use Qualimetrix\Baseline\BaselineCapture;
use Qualimetrix\Baseline\BaselineEntry;
use Qualimetrix\Baseline\BaselineIdentity;
use Qualimetrix\Baseline\BaselineMigrator;
use Qualimetrix\Baseline\UncapturedGroup;
use Qualimetrix\Baseline\UncapturedReason;
use Qualimetrix\Baseline\V5Baseline;
use Qualimetrix\Baseline\V5BaselineReader;
use Qualimetrix\Baseline\V5Entry;
use Qualimetrix\Baseline\V5UnreadableRecord;
use Qualimetrix\Core\Violation\ViolationChannel;

/**
 * A v5 file carries only `(symbolKey, rule)` — no magnitude, no
 * `violationCode`, no edge — so {@see BaselineMigrator} can only match on
 * that pair against the fresh capture's v10 entries. The fixture below is
 * built so every migration-report group defined by ADR 0017 is exercised by one run:
 *
 * - `method:App\Foo::bar` / `complexity.cyclomatic` — the fresh capture
 *   wrote two v10 entries under it (method- and class-level violation
 *   codes share one rule name), from two v5 records — **carried**.
 * - `class:App\Foo` / `design.god-class` — one v5 record, one v10 entry —
 *   also **carried**.
 * - `method:App\Foo::baz` / `coupling.cbo` — a v5 record with nothing
 *   backing it in the fresh capture — **dropped**.
 * - `method:App\Foo::qux` / `size.method-count` — a v10 entry the v5 file
 *   never mentioned — **fresh**.
 */
#[CoversClass(BaselineMigrator::class)]
final class BaselineMigratorTest extends TestCase
{
    private BaselineMigrator $migrator;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->migrator = new BaselineMigrator(new V5BaselineReader());
        $this->tempDir = sys_get_temp_dir() . '/qmx_migrator_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    unlink($file);
                }
            }
            rmdir($this->tempDir);
        }
    }

    #[Test]
    public function itCountsCarriedPairsByHowManyEntriesEnteredAndCameOut(): void
    {
        $result = $this->migrator->migrate($this->v5Fixture(), $this->freshCaptureFixture());

        self::assertSame(3, $result->report->carriedV5EntryCount);
        self::assertSame(3, $result->report->carriedV10EntryCount);
    }

    #[Test]
    public function itListsDroppedPairsFullyByNameNotJustByCount(): void
    {
        $result = $this->migrator->migrate($this->v5Fixture(), $this->freshCaptureFixture());

        self::assertCount(1, $result->report->dropped);
        self::assertSame('method:App\Foo::baz', $result->report->dropped[0]->symbolKey);
        self::assertSame('coupling.cbo', $result->report->dropped[0]->rule);
    }

    #[Test]
    public function itCountsFreshV10EntriesUnderPairsTheV5FileNeverMentioned(): void
    {
        $result = $this->migrator->migrate($this->v5Fixture(), $this->freshCaptureFixture());

        self::assertSame(1, $result->report->freshV10EntryCount);
    }

    #[Test]
    public function itCarriesTheUncapturedGroupCountFromTheFreshCapture(): void
    {
        $result = $this->migrator->migrate($this->v5Fixture(), $this->freshCaptureFixture());

        self::assertSame(1, $result->report->uncapturedGroupCount);
    }

    #[Test]
    public function itWritesExactlyTheFreshCaptureAndMergesNothingFromV5(): void
    {
        $freshCapture = $this->freshCaptureFixture();

        $result = $this->migrator->migrate($this->v5Fixture(), $freshCapture);

        self::assertSame($freshCapture->baseline, $result->baseline);
    }

    /**
     * A row the reader could not parse belongs to none of the three groups —
     * it never became a pair to classify — so it travels into the report
     * verbatim. Losing it here would undo the reader's whole reason for
     * collecting it: `migrate` runs once, and the report is where a user
     * finds out what did not come across.
     */
    #[Test]
    public function itCarriesTheV5FilesUnreadableRowsIntoTheReport(): void
    {
        $unreadable = [new V5UnreadableRecord('class:App\Broken', '"hash" is missing')];
        $v5 = new V5Baseline($this->v5Fixture()->entries, $unreadable);

        $report = $this->migrator->migrate($v5, $this->freshCaptureFixture())->report;

        self::assertSame($unreadable, $report->unreadableV5Records);
    }

    #[Test]
    public function itNeedsNoForceToOverwriteAV5Destination(): void
    {
        $path = $this->writeJson(<<<'JSON'
            {"version": 5, "generated": "2026-01-01T00:00:00+00:00", "violations": {}}
            JSON);

        self::assertFalse($this->migrator->destinationRequiresForce($path));
    }

    #[Test]
    public function itNeedsForceToOverwriteAV10Destination(): void
    {
        $path = $this->writeJson(<<<'JSON'
            {"version": 10, "generated": "2026-01-01T00:00:00+00:00", "scope": [], "entries": {}}
            JSON);

        self::assertTrue($this->migrator->destinationRequiresForce($path));
    }

    private function v5Fixture(): V5Baseline
    {
        return new V5Baseline([
            new V5Entry('method:App\Foo::bar', 'complexity.cyclomatic', '0000000000000001'),
            new V5Entry('method:App\Foo::bar', 'complexity.cyclomatic', '0000000000000002'),
            new V5Entry('method:App\Foo::baz', 'coupling.cbo', '0000000000000003'),
            new V5Entry('class:App\Foo', 'design.god-class', '0000000000000004'),
        ]);
    }

    private function freshCaptureFixture(): BaselineCapture
    {
        $baseline = new Baseline(
            generated: new DateTimeImmutable('2026-08-05T12:00:00+03:00'),
            scope: ['src'],
            entries: [
                new BaselineEntry(
                    new BaselineIdentity(
                        'method:App\Foo::bar',
                        new ViolationChannel('complexity.cyclomatic', 'complexity.cyclomatic.method'),
                    ),
                    [25],
                    1,
                ),
                new BaselineEntry(
                    new BaselineIdentity(
                        'method:App\Foo::bar',
                        new ViolationChannel('complexity.cyclomatic', 'complexity.cyclomatic.class'),
                    ),
                    [30],
                    1,
                ),
                new BaselineEntry(
                    new BaselineIdentity(
                        'class:App\Foo',
                        new ViolationChannel('design.god-class', 'design.god-class'),
                    ),
                    null,
                    1,
                ),
                new BaselineEntry(
                    new BaselineIdentity(
                        'method:App\Foo::qux',
                        new ViolationChannel('size.method-count', 'size.method-count'),
                    ),
                    null,
                    1,
                ),
            ],
        );

        $uncaptured = [
            new UncapturedGroup(
                new BaselineIdentity('method:App\Foo::quux', new ViolationChannel('computed.custom', 'computed.custom')),
                UncapturedReason::UndeclaredChannel,
                1,
            ),
        ];

        return new BaselineCapture($baseline, $uncaptured);
    }

    private function writeJson(string $json): string
    {
        $path = $this->tempDir . '/baseline.json';
        file_put_contents($path, $json);

        return $path;
    }
}
