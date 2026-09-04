<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineCapture;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\BaselineMigrator;
use Qualimetrix\Analysis\Policy\Baseline\UncapturedGroup;
use Qualimetrix\Analysis\Policy\Baseline\UncapturedReason;
use Qualimetrix\Analysis\Policy\Baseline\V5Baseline;
use Qualimetrix\Analysis\Policy\Baseline\V5BaselineReader;
use Qualimetrix\Analysis\Policy\Baseline\V5Entry;
use Qualimetrix\Analysis\Policy\Baseline\V5UnreadableRecord;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * A v5 file carries only `(symbolKey, rule)` — no magnitude, no
 * `code`, no edge — so {@see BaselineMigrator} can only match on
 * that pair against the fresh capture's v10 entries. The fixture below is
 * built so every migration-report group defined by ADR 0017 is exercised by one run:
 *
 * - `method:App\Foo::bar` / `complexity.cyclomatic` — the two legacy v5
 *   records do not match the fresh capture's `callable:App\Foo::bar` entries,
 *   so both are **dropped** and both v10 entries are **fresh**.
 * - `class:App\Foo` / `design.god-class` — one v5 record, one v10 entry —
 *   also **carried**.
 * - `method:App\Foo::baz` / `coupling.cbo` — a v5 record with nothing
 *   backing it in the fresh capture — **dropped**.
 * - `callable:App\Foo::qux` / `size.method-count` — a v10 entry the v5 file
 *   never mentioned — **fresh**.
 */
#[CoversClass(BaselineMigrator::class)]
final class BaselineMigratorTest extends TestCase
{
    private BaselineMigrator $migrator;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->migrator = new BaselineMigrator(new V5BaselineReader(), self::producerEdge());
        $this->tempDir = sys_get_temp_dir() . '/qmx_migrator_test_' . bin2hex(random_bytes(6));
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

        self::assertSame(1, $result->report->carriedV5EntryCount);
        self::assertSame(1, $result->report->carriedV10EntryCount);
    }

    #[Test]
    public function itListsDroppedPairsFullyByNameNotJustByCount(): void
    {
        $result = $this->migrator->migrate($this->v5Fixture(), $this->freshCaptureFixture());

        self::assertSame(
            [
                ['method:App\Foo::bar', 'complexity.cyclomatic'],
                ['method:App\Foo::baz', 'coupling.cbo'],
            ],
            array_map(
                static fn($entry): array => [$entry->symbolKey, $entry->rule],
                $result->report->dropped,
            ),
        );
    }

    #[Test]
    public function itCountsFreshV10EntriesUnderPairsTheV5FileNeverMentioned(): void
    {
        $result = $this->migrator->migrate($this->v5Fixture(), $this->freshCaptureFixture());

        self::assertSame(3, $result->report->freshV10EntryCount);
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
                        'callable:App\Foo::bar',
                        new FindingChannel('complexity.cyclomatic.callable'),
                    ),
                    [25],
                    1,
                ),
                new BaselineEntry(
                    new BaselineIdentity(
                        'callable:App\Foo::bar',
                        new FindingChannel('complexity.cyclomatic.class'),
                    ),
                    [30],
                    1,
                ),
                new BaselineEntry(
                    new BaselineIdentity(
                        'class:App\Foo',
                        new FindingChannel('design.god-class'),
                    ),
                    null,
                    1,
                ),
                new BaselineEntry(
                    new BaselineIdentity(
                        'callable:App\Foo::qux',
                        new FindingChannel('size.method-count'),
                    ),
                    null,
                    1,
                ),
            ],
        );

        $uncaptured = [
            new UncapturedGroup(
                new BaselineIdentity('callable:App\Foo::quux', new FindingChannel('computed.custom')),
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

    /**
     * The channel-to-producer edge a v5 record is matched through: the channel's
     * own name, minus a trailing level segment where it carries one — the same
     * relation the retired left half of a channel key encoded.
     */
    private static function producerEdge(): ChannelIdentityInterface
    {
        $identity = self::createStub(ChannelIdentityInterface::class);
        $identity->method('producerOf')->willReturnCallback(static function (string $code): string {
            $lastDot = strrpos($code, '.');

            return $lastDot !== false && SymbolLevel::tryFrom(substr($code, $lastDot + 1)) !== null
                ? substr($code, 0, $lastDot)
                : $code;
        });

        return $identity;
    }
}
