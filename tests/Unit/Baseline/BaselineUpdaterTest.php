<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Baseline;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\Baseline;
use Qualimetrix\Baseline\BaselineEntry;
use Qualimetrix\Baseline\BaselineEntryMode;
use Qualimetrix\Baseline\BaselineIdentity;
use Qualimetrix\Baseline\BaselineUpdateDisposition;
use Qualimetrix\Baseline\BaselineUpdater;
use Qualimetrix\Baseline\BaselineUpdateRefusalReason;
use Qualimetrix\Baseline\EntrySelector;
use Qualimetrix\Baseline\InertBaselineEntry;
use Qualimetrix\Baseline\InertEntryReason;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Core\Violation\ViolationChannel;
use Qualimetrix\Tests\Support\Time\FixedClock;
use Qualimetrix\Tests\Support\Violation\StubChannelDeclarationRegistry;
use Qualimetrix\Tests\Support\Violation\ViolationFactory;

/**
 * §7's rules for `baseline:update`, exercised through the domain service
 * itself rather than through {@see \Qualimetrix\Baseline\GroupAcceptance}
 * directly — `GroupAcceptanceTest` already pins the primitive; this pins
 * that `BaselineUpdater` actually calls it and does the right thing with the
 * verdict: write, refuse, or leave untouched.
 */
#[CoversClass(BaselineUpdater::class)]
final class BaselineUpdaterTest extends TestCase
{
    /**
     * **The case that killed the per-position rule (§7, §5.1).** Stored
     * `[40, 100]` on a `higher` channel, the 40-line duplicate repaired,
     * measured group `[100]`. A per-position rule reads rank 0 growing from
     * 40 to 100 and refuses; the group rule accepts it because `{100}` sits
     * inside `{100, 40}`.
     */
    #[Test]
    public function itAcceptsAndWritesAShrunkGroup(): void
    {
        $symbol = SymbolPath::forFile(RelativePath::fromString('src/Legacy/dup.php'));
        $stored = new BaselineEntry(
            new BaselineIdentity($symbol->toCanonical(), self::duplicationChannel()),
            [40, 100],
            2,
        );

        $current = ViolationFactory::magnitude($symbol, 100, 'duplication.code-duplication', 'duplication.code-duplication');

        $result = $this->updater()->update(self::baselineOf($stored), [$current], ['src']);

        self::assertSame(BaselineUpdateDisposition::Updated, $result->outcomes[0]->disposition);
        self::assertSame([100.0], $result->baseline->entries[0]->magnitudes);
        self::assertSame(1, $result->baseline->entries[0]->count);
    }

    /**
     * **The `lower`-channel count widening that must be refused (§7).**
     * Stored `[40]`; the measured group is `[55, 70]` — both members
     * individually improved over the one stored value, but the group
     * doubled in size. §5.1's cumulative rule catches this without a
     * separate count check: at `t = 70` there are two current members at
     * least that bad and only one stored one.
     */
    #[Test]
    public function itRefusesALowerChannelGroupThatGrewInSizeThoughEveryMemberImproved(): void
    {
        $symbol = SymbolPath::forClass('App', 'Service');
        $stored = new BaselineEntry(
            new BaselineIdentity($symbol->toCanonical(), self::maintainabilityChannel()),
            [40],
            1,
        );

        $current = [
            ViolationFactory::magnitude($symbol, 55, 'maintainability.index', 'maintainability.index.class'),
            ViolationFactory::magnitude($symbol, 70, 'maintainability.index', 'maintainability.index.class'),
        ];

        $result = $this->updater()->update(self::baselineOf($stored), $current, ['src']);

        self::assertSame(BaselineUpdateDisposition::Refused, $result->outcomes[0]->disposition);
        self::assertSame(BaselineUpdateRefusalReason::Worsened, $result->outcomes[0]->refusalReason);
        self::assertSame([40.0], $result->baseline->entries[0]->magnitudes, 'the stored entry is written back unchanged');
        self::assertSame(1, $result->baseline->entries[0]->count);
    }

    #[Test]
    public function itLeavesAVanishedGroupUntouched(): void
    {
        $symbol = SymbolPath::forMethod('App', 'Foo', 'bar');
        $stored = new BaselineEntry(BaselineIdentity::forViolation(ViolationFactory::magnitude($symbol, 25)), [25], 1);

        $result = $this->updater()->update(self::baselineOf($stored), [], ['src']);

        self::assertSame(BaselineUpdateDisposition::Skipped, $result->outcomes[0]->disposition);
        self::assertSame($stored, $result->baseline->entries[0], 'the untouched entry is the exact same object, not a rebuilt copy');
    }

    #[Test]
    public function itNeverAddsAnIdentityTheBaselineDidNotAlreadyHold(): void
    {
        $baseline = new Baseline(generated: new DateTimeImmutable(), scope: [], entries: []);
        $found = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 25);

        $result = $this->updater()->update($baseline, [$found], ['src']);

        self::assertSame(0, $result->baseline->count());
        self::assertSame([], $result->outcomes);
    }

    #[Test]
    public function itCarriesInertEntriesForwardVerbatim(): void
    {
        $inert = new InertBaselineEntry(
            symbolKey: 'file:src/Legacy.php',
            channelKey: null,
            identity: null,
            selector: EntrySelector::forKey('file:src/Legacy.php'),
            reason: InertEntryReason::Malformed,
            detail: 'entry must be a JSON object',
            raw: 'garbage',
        );

        $baseline = new Baseline(generated: new DateTimeImmutable(), scope: ['src'], entries: [], inertEntries: [$inert]);

        $result = $this->updater()->update($baseline, [], ['src']);

        self::assertSame([$inert], $result->baseline->inertEntries);
    }

    #[Test]
    public function itPreservesModeOnAWrittenEntry(): void
    {
        $symbol = SymbolPath::forMethod('App', 'Foo', 'bar');
        $stored = new BaselineEntry(
            BaselineIdentity::forViolation(ViolationFactory::magnitude($symbol, 25)),
            [25],
            1,
            BaselineEntryMode::Suppress,
        );

        $current = ViolationFactory::magnitude($symbol, 20);

        $result = $this->updater()->update(self::baselineOf($stored), [$current], ['src']);

        self::assertSame(BaselineEntryMode::Suppress, $result->baseline->entries[0]->mode);
    }

    #[Test]
    public function itRefusesAnEntryOnAChannelNoRuleDeclares(): void
    {
        $symbol = SymbolPath::forMethod('App', 'Foo', 'bar');
        $violation = ViolationFactory::magnitude($symbol, 5, 'nobody.declares', 'this.channel');
        $stored = new BaselineEntry(BaselineIdentity::forViolation($violation), [5], 1);

        $result = (new BaselineUpdater(new StubChannelDeclarationRegistry(), new FixedClock()))
            ->update(self::baselineOf($stored), [$violation], ['src']);

        self::assertSame(BaselineUpdateDisposition::Refused, $result->outcomes[0]->disposition);
        self::assertSame(BaselineUpdateRefusalReason::UndeclaredChannel, $result->outcomes[0]->refusalReason);
        self::assertSame([5.0], $result->baseline->entries[0]->magnitudes);
    }

    /**
     * A `Baseline` assembled in memory can hold an entry whose own shape
     * disagrees with what the channel currently declares — the loader would
     * refuse such a line, but a lifecycle command building a `Baseline`
     * directly bypasses the loader entirely (mirrors
     * {@see \Qualimetrix\Baseline\Filter\BaselineCeilingStage}'s identical
     * reachability note).
     */
    #[Test]
    public function itRefusesAnEntryWhoseShapeDisagreesWithItsChannel(): void
    {
        $symbol = SymbolPath::forFile(RelativePath::fromString('src/Legacy.php'));
        // "code-smell.goto" is declared `occurrence` by the stub registry,
        // but this entry stores magnitudes — a shape mismatch that can only
        // arise from a Baseline assembled directly, not through the loader.
        $identity = new BaselineIdentity($symbol->toCanonical(), self::gotoChannel());
        $stored = new BaselineEntry($identity, [1.0], 1);

        $current = ViolationFactory::occurrence($symbol);

        $result = $this->updater()->update(self::baselineOf($stored), [$current], ['src']);

        self::assertSame(BaselineUpdateDisposition::Refused, $result->outcomes[0]->disposition);
        self::assertSame(BaselineUpdateRefusalReason::ShapeMismatch, $result->outcomes[0]->refusalReason);
    }

    #[Test]
    public function itRefusesAMagnitudeEntryWhoseMeasuredGroupReportsNoFiniteNumber(): void
    {
        $symbol = SymbolPath::forMethod('App', 'Foo', 'bar');
        $stored = new BaselineEntry(BaselineIdentity::forViolation(ViolationFactory::magnitude($symbol, 15)), [15], 1);

        $noNumber = new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 1),
            symbolPath: $symbol,
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.method',
            message: 'no magnitude reported',
            severity: Severity::Warning,
        );

        $result = $this->updater()->update(self::baselineOf($stored), [$noNumber], ['src']);

        self::assertSame(BaselineUpdateDisposition::Refused, $result->outcomes[0]->disposition);
        self::assertSame(BaselineUpdateRefusalReason::CurrentMagnitudeUnavailable, $result->outcomes[0]->refusalReason);
        self::assertSame([15.0], $result->baseline->entries[0]->magnitudes);
    }

    #[Test]
    public function itStampsTheResultFromTheInjectedClockAndRunScope(): void
    {
        $baseline = new Baseline(generated: new DateTimeImmutable('2020-01-01T00:00:00+00:00'), scope: ['tests'], entries: []);

        $result = $this->updater()->update($baseline, [], ['src', 'src/']);

        self::assertSame('2026-08-05T12:00:00+03:00', $result->baseline->generated->format('c'));
        self::assertSame(['src'], $result->baseline->scope);
    }

    #[Test]
    public function itCarriesTheSourceContentHashForward(): void
    {
        $baseline = new Baseline(generated: new DateTimeImmutable(), scope: ['src'], entries: [], sourceContentHash: 'abc123');

        $result = $this->updater()->update($baseline, [], ['src']);

        self::assertSame('abc123', $result->baseline->sourceContentHash);
    }

    private function updater(): BaselineUpdater
    {
        return new BaselineUpdater(StubChannelDeclarationRegistry::withDefaults(), new FixedClock());
    }

    private static function baselineOf(BaselineEntry $entry): Baseline
    {
        return new Baseline(generated: new DateTimeImmutable(), scope: ['src'], entries: [$entry]);
    }

    private static function duplicationChannel(): ViolationChannel
    {
        return new ViolationChannel('duplication.code-duplication', 'duplication.code-duplication');
    }

    private static function maintainabilityChannel(): ViolationChannel
    {
        return new ViolationChannel('maintainability.index', 'maintainability.index.class');
    }

    private static function gotoChannel(): ViolationChannel
    {
        return new ViolationChannel('code-smell.goto', 'code-smell.goto');
    }
}
