<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\EntrySelector;
use Qualimetrix\Analysis\Policy\Baseline\InertBaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\InertEntryReason;

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
            new FindingChannel('code-smell.goto'),
        )));
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
        $cyclomatic = self::entry($symbol, 'complexity.cyclomatic', 'complexity.cyclomatic');

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
            $baseline->subjectKeys(),
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
        string $code = 'code-smell.goto',
    ): BaselineEntry {
        return new BaselineEntry(
            new BaselineIdentity($symbolKey, new FindingChannel($code)),
            null,
            1,
        );
    }

    private static function inert(string $symbolKey): InertBaselineEntry
    {
        return new InertBaselineEntry(
            subjectKey: $symbolKey,
            channelKey: null,
            identity: null,
            selector: EntrySelector::forKey($symbolKey),
            reason: InertEntryReason::Malformed,
            detail: 'entry must be a JSON object',
            raw: 'garbage',
        );
    }
}
