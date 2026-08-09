<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Baseline;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\Baseline;
use Qualimetrix\Baseline\BaselineEdge;
use Qualimetrix\Baseline\BaselineEntry;
use Qualimetrix\Baseline\BaselineIdentity;
use Qualimetrix\Baseline\EntrySelector;
use Qualimetrix\Baseline\InertBaselineEntry;
use Qualimetrix\Baseline\InertEntryReason;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Violation\ViolationChannel;

#[CoversClass(Baseline::class)]
#[CoversClass(InertBaselineEntry::class)]
final class BaselineTest extends TestCase
{
    #[Test]
    public function itCountsApplicableEntriesOnly(): void
    {
        $baseline = new Baseline(
            generated: new DateTimeImmutable(),
            scope: ['src'],
            entries: [self::entry('callable:App\Foo::bar'), self::entry('callable:App\Foo::baz')],
            inertEntries: [self::inert('callable:App\Foo::qux')],
        );

        self::assertSame(2, $baseline->count());
    }

    #[Test]
    public function itFindsAnEntryByItsIdentity(): void
    {
        $entry = self::entry('callable:App\Foo::bar');
        $baseline = self::baselineOf($entry);

        self::assertTrue($baseline->hasIdentity($entry->identity));
        self::assertSame($entry, $baseline->findByIdentity($entry->identity));
    }

    #[Test]
    public function itDoesNotFindAnIdentityItDoesNotHold(): void
    {
        $baseline = self::baselineOf(self::entry('callable:App\Foo::bar'));

        self::assertFalse($baseline->hasIdentity(new BaselineIdentity(
            'callable:App\Foo::other',
            new ViolationChannel('code-smell.goto', 'code-smell.goto'),
        )));
    }

    /**
     * The case `<symbol>#<channel>` cannot address: two forbidden edges out
     * of one class on one channel agree on every component but the edge.
     */
    #[Test]
    public function itAddressesExactlyOneOfTwoEntriesDifferingOnlyByEdge(): void
    {
        $channel = new ViolationChannel('architecture.layer-violation', 'architecture.layer-violation');
        $toConnection = new BaselineEntry(
            new BaselineIdentity(
                'class:App\Web\Controller',
                $channel,
                new BaselineEdge('class:App\Db\Connection', DependencyType::New_),
            ),
            null,
            1,
        );
        $toStatement = new BaselineEntry(
            new BaselineIdentity(
                'class:App\Web\Controller',
                $channel,
                new BaselineEdge('class:App\Db\Statement', DependencyType::New_),
            ),
            null,
            1,
        );

        $baseline = self::baselineOf($toConnection, $toStatement);

        $found = $baseline->findBySelector($toStatement->selector());

        self::assertCount(1, $found);
        self::assertSame($toStatement, $found[0]);
    }

    #[Test]
    public function itFindsAnInertEntryByItsSelectorToo(): void
    {
        $inert = self::inert('callable:App\Foo::qux');
        $baseline = new Baseline(
            generated: new DateTimeImmutable(),
            scope: [],
            entries: [],
            inertEntries: [$inert],
        );

        self::assertSame([$inert], $baseline->findBySelector($inert->selector->value));
    }

    #[Test]
    public function itFindsNothingForAStringThatIsNotASelector(): void
    {
        $baseline = self::baselineOf(self::entry('callable:App\Foo::bar'));

        self::assertSame([], $baseline->findBySelector('not-a-selector'));
    }

    #[Test]
    public function itReportsEntriesWhoseIdentityDidNotAppearInTheRun(): void
    {
        $repaired = self::entry('callable:App\Foo::bar');
        $stillFailing = self::entry('callable:App\Foo::baz');

        $baseline = self::baselineOf($repaired, $stillFailing);

        $stale = $baseline->staleEntries([$stillFailing->identity->key()]);

        self::assertCount(1, $stale);
        self::assertSame($repaired, $stale[0]);
    }

    /**
     * Under the finer identity a repaired finding strands its own entry and
     * leaves its neighbours under the same symbol applying.
     */
    #[Test]
    public function itLeavesSiblingEntriesUnderOneSymbolAloneWhenOneGoesStale(): void
    {
        $symbol = 'callable:App\Foo::bar';
        $goto = self::entry($symbol, 'code-smell.goto', 'code-smell.goto');
        $cyclomatic = self::entry($symbol, 'complexity.cyclomatic', 'complexity.cyclomatic.callable');

        $baseline = self::baselineOf($goto, $cyclomatic);

        self::assertSame([$goto], $baseline->staleEntries([$cyclomatic->identity->key()]));
    }

    #[Test]
    public function itRejectsTwoEntriesClaimingOneIdentity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::baselineOf(self::entry('callable:App\Foo::bar'), self::entry('callable:App\Foo::bar'));
    }

    #[Test]
    public function itListsTheSymbolKeysOfValidAndInertEntriesAlike(): void
    {
        $baseline = new Baseline(
            generated: new DateTimeImmutable(),
            scope: [],
            entries: [self::entry('callable:App\Foo::bar')],
            inertEntries: [self::inert('file:src/Legacy.php')],
        );

        self::assertEqualsCanonicalizing(
            ['callable:App\Foo::bar', 'file:src/Legacy.php'],
            $baseline->symbolKeys(),
        );
    }

    #[Test]
    public function itDropsItsCompareAndSwapTokenWhenDetached(): void
    {
        $baseline = new Baseline(
            generated: new DateTimeImmutable(),
            scope: ['src'],
            entries: [self::entry('callable:App\Foo::bar')],
            sourceContentHash: 'abc',
        );

        self::assertNull($baseline->detached()->sourceContentHash);
        self::assertSame($baseline->count(), $baseline->detached()->count());
    }

    #[Test]
    public function itDistinguishesExpectedAbsenceFromUncheckedProvenance(): void
    {
        $unchecked = self::baselineOf(self::entry('callable:App\Foo::bar'));
        $expectedAbsent = $unchecked->withExpectedSourceAbsence();
        $expectedContent = $expectedAbsent->withSourceContentHash('abc');

        self::assertNull($unchecked->sourceContentHash);
        self::assertFalse($unchecked->expectsSourceAbsence);
        self::assertNull($expectedAbsent->sourceContentHash);
        self::assertTrue($expectedAbsent->expectsSourceAbsence);
        self::assertSame('abc', $expectedContent->sourceContentHash);
        self::assertFalse($expectedContent->expectsSourceAbsence);
        self::assertFalse($expectedAbsent->detached()->expectsSourceAbsence);
    }

    #[Test]
    public function itRejectsMutuallyExclusiveSourceProvenance(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Baseline(
            generated: new DateTimeImmutable(),
            scope: [],
            entries: [],
            sourceContentHash: 'abc',
            expectsSourceAbsence: true,
        );
    }

    private static function baselineOf(BaselineEntry ...$entries): Baseline
    {
        return new Baseline(
            generated: new DateTimeImmutable('2026-08-05T12:00:00+03:00'),
            scope: ['src'],
            entries: array_values($entries),
        );
    }

    private static function entry(
        string $symbolKey,
        string $ruleName = 'code-smell.goto',
        string $violationCode = 'code-smell.goto',
    ): BaselineEntry {
        return new BaselineEntry(
            new BaselineIdentity($symbolKey, new ViolationChannel($ruleName, $violationCode)),
            null,
            1,
        );
    }

    private static function inert(string $symbolKey): InertBaselineEntry
    {
        return new InertBaselineEntry(
            symbolKey: $symbolKey,
            channelKey: null,
            identity: null,
            selector: EntrySelector::forKey($symbolKey),
            reason: InertEntryReason::Malformed,
            detail: 'entry must be a JSON object',
            raw: 'garbage',
        );
    }
}
