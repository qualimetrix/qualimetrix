<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryMode;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\Filter\BaselineCeilingStage;
use Qualimetrix\Analysis\Policy\Baseline\Filter\GroupCeilingVerdict;
use Qualimetrix\Analysis\Policy\Baseline\InertBaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\InertEntryReason;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;
use Qualimetrix\Tests\Analysis\Finding\Support\ViolationFactory;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Fixtures\CeilingStageFixtures;

/**
 * ADR 0017 governing invariant, asserted one ambiguity at a time: *an entry
 * that cannot be applied does not suppress* — and does not
 * promote either.
 *
 * Every case here asserts both halves. Asserting only "not suppressed" would
 * pass on an implementation that answers a version-skewed declaration by
 * turning a whole channel's worth of unchanged findings into build failures,
 * which is the precise harm the fail-safe direction exists to prevent.
 *
 * **One listed ambiguity is unreachable by construction and is covered by
 * the undeclared-channel case:** "a declaration missing its direction".
 * {@see ChannelDeclaration} refuses to exist as a `magnitude` without a
 * {@see WorseDirection}, so the only shape a missing direction can take is a
 * missing declaration.
 */
#[CoversClass(BaselineCeilingStage::class)]
#[CoversClass(GroupCeilingVerdict::class)]
final class BaselineCeilingStageFailSafeTest extends TestCase
{
    use CeilingStageFixtures;

    /**
     * @return iterable<string, array{float}>
     */
    public static function provideNonFiniteMagnitudes(): iterable
    {
        yield 'not a number' => [\NAN];
        yield 'positive infinity' => [\INF];
        yield 'negative infinity' => [-\INF];
    }

    /**
     * An unrecognized `mode` and a malformed line reach the stage the only
     * way they can: the loader has already refused to build a valid entry
     * out of them, so the group finds nothing that bounds it.
     *
     * @return iterable<string, array{InertEntryReason}>
     */
    public static function provideInertReasons(): iterable
    {
        yield 'unrecognized mode' => [InertEntryReason::UnrecognizedMode];
        yield 'malformed entry' => [InertEntryReason::Malformed];
        yield 'duplicate identity' => [InertEntryReason::DuplicateIdentity];
    }

    #[Test]
    public function itReportsAGroupOnAChannelNoRuleDeclares(): void
    {
        $finding = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15);
        $stage = self::stageOver(
            self::baselineOf([self::magnitudeEntry($finding, [15])]),
            new StubChannelDeclarationRegistry(),
        );

        self::assertReportedUntouched($stage->apply([$finding])->violations, $finding);
    }

    /**
     * **`mode: suppress` is not a way out of the invariant.** It waives the
     * comparison of magnitudes and count (ADR 0017); it does not answer the
     * prior question of whether the entry bounds this channel at all, and
     * neither ADR 0017's "a channel that declares neither … its entries do not
     * suppress" nor ADR 0017 "an entry that addresses an undeclared channel does
     * not suppress" carries a `mode` exception.
     *
     * The loader refuses such an entry before the stage sees it, whatever
     * its `mode`, so this is not reachable through a baseline file today. It
     * is asserted anyway because the commands that assemble a baseline in
     * memory bypass the loader, and the failure this guards against is the
     * silent one: a finding hidden by an entry naming a channel nothing
     * declares.
     */
    #[Test]
    public function itDoesNotLetASuppressEntryHideAGroupOnAChannelNoRuleDeclares(): void
    {
        $finding = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15);
        $stage = self::stageOver(
            self::baselineOf([self::magnitudeEntry($finding, [15], BaselineEntryMode::Suppress)]),
            new StubChannelDeclarationRegistry(),
        );

        self::assertReportedUntouched($stage->apply([$finding])->violations, $finding);
    }

    /**
     * The same ordering on the other half of applicability: an entry whose
     * shape disagrees with its channel's does not suppress, and saying
     * `suppress` does not change that.
     */
    #[Test]
    public function itDoesNotLetASuppressEntryHideAGroupWhoseShapeDisagreesWithItsChannel(): void
    {
        $finding = ViolationFactory::occurrence(SymbolPath::forFile(RelativePath::fromString('src/Legacy.php')));

        $stage = self::stageOver(self::baselineOf([
            self::magnitudeEntry($finding, [1.0], BaselineEntryMode::Suppress),
        ]));

        self::assertReportedUntouched($stage->apply([$finding])->violations, $finding);
    }

    #[Test]
    public function itReportsAMagnitudeGroupWhoseEntryStoresNoMagnitudes(): void
    {
        $finding = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15);

        // A magnitude channel bounded only by a count would silently accept
        // unbounded growth, so the entry is not applied at all.
        $stage = self::stageOver(self::baselineOf([self::occurrenceEntry($finding, 1)]));

        self::assertReportedUntouched($stage->apply([$finding])->violations, $finding);
    }

    #[Test]
    public function itReportsAnOccurrenceGroupWhoseEntryStoresMagnitudes(): void
    {
        $finding = ViolationFactory::occurrence(SymbolPath::forFile(RelativePath::fromString('src/Legacy.php')));

        $stage = self::stageOver(self::baselineOf([self::magnitudeEntry($finding, [1.0])]));

        self::assertReportedUntouched($stage->apply([$finding])->violations, $finding);
    }

    #[Test]
    public function itReportsAMagnitudeGroupWhoseMemberReportsNoNumberAtAll(): void
    {
        $recorded = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15);
        $current = self::findingOn(
            'complexity.cyclomatic',
            'complexity.cyclomatic.callable',
            SymbolPath::forMethod('App', 'Foo', 'bar'),
            null,
        );

        $stage = self::stageOver(self::baselineOf([self::magnitudeEntry($recorded, [15])]));

        self::assertReportedUntouched($stage->apply([$current])->violations, $current);
    }

    /**
     * **One member without a number disqualifies the whole group**, and that
     * is a decision rather than a consequence: the alternative — comparing
     * the members that do report a number and ignoring the rest — would
     * measure a smaller group than the one that exists and could only ever
     * under-report.
     *
     * The case is built so the decision is visible. Stored `[40, 100]`,
     * current `[40, —]`: had the numberless member been dropped, `[40]`
     * would sit comfortably inside the ceiling and the whole group would
     * have been suppressed. Both members come back instead, at their own
     * severity.
     */
    #[Test]
    public function itReportsAWholeMagnitudeGroupWhenOnlyOneOfItsMembersReportsNoNumber(): void
    {
        $withNumber = self::duplicationFinding(40, 1);
        $withoutNumber = self::duplicationFinding(null, 2);

        $stage = self::stageOver(self::baselineOf([self::magnitudeEntry($withNumber, [40, 100])]));

        $reported = $stage->apply([$withNumber, $withoutNumber])->violations;

        self::assertSame([$withNumber, $withoutNumber], $reported, 'the whole group is reported, untouched');
        self::assertSame([Severity::Warning, Severity::Warning], self::severitiesOf($reported));
        self::assertNull($reported[0]->acceptedLevel, 'a fail-safe path must never promote');
        self::assertNull($reported[1]->acceptedLevel);
    }

    #[Test]
    #[DataProvider('provideNonFiniteMagnitudes')]
    public function itReportsAMagnitudeGroupWhoseMemberReportsANonFiniteNumber(float $metricValue): void
    {
        $recorded = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15);
        $current = self::findingOn(
            'complexity.cyclomatic',
            'complexity.cyclomatic.callable',
            SymbolPath::forMethod('App', 'Foo', 'bar'),
            $metricValue,
        );

        $stage = self::stageOver(self::baselineOf([self::magnitudeEntry($recorded, [15])]));

        self::assertReportedUntouched($stage->apply([$current])->violations, $current);
    }

    #[Test]
    #[DataProvider('provideInertReasons')]
    public function itReportsAGroupWhoseOnlyEntryTheLoaderCouldNotApply(InertEntryReason $reason): void
    {
        $finding = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15);

        $stage = self::stageOver(self::baselineOf([], [
            InertBaselineEntry::forIdentity(
                BaselineIdentity::forViolation($finding),
                $reason,
                'the file said something this build does not read',
                ['channel' => 'complexity.cyclomatic#complexity.cyclomatic.callable'],
            ),
        ]));

        self::assertReportedUntouched($stage->apply([$finding])->violations, $finding);
    }

    /**
     * ADR 0017: a rename produces a fresh finding and strands the old entry.
     * Noisy rather than silent, and in particular not a breach — the entry
     * measured nothing.
     */
    #[Test]
    public function itReportsARenamedSymbolAndStrandsItsEntry(): void
    {
        $before = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'calculate'), 15);
        $after = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'compute'), 15);

        $entry = self::magnitudeEntry($before, [15]);
        $stage = self::stageOver(self::baselineOf([$entry]));

        self::assertReportedUntouched($stage->apply([$after])->violations, $after);
        self::assertSame([$entry], $stage->judgeAll([$after])->staleEntries);
    }

    /**
     * A stale entry must not disable the entries around it: under the
     * per-identity key the first repair on a multi-channel symbol would
     * otherwise resurface every accepted finding at once.
     */
    #[Test]
    public function itKeepsApplyingItsOtherEntriesWhileOneIsStale(): void
    {
        $repaired = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'gone'), 15);
        $accepted = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'kept'), 15);

        $stage = self::stageOver(self::baselineOf([
            self::magnitudeEntry($repaired, [15]),
            self::magnitudeEntry($accepted, [15]),
        ]));

        $result = $stage->apply([$accepted]);

        self::assertSame([], $result->violations);
        self::assertCount(1, $stage->judgeAll([$accepted])->staleEntries);
    }

    /**
     * A magnitude channel whose declaration has been corrected in a later
     * release: every existing entry mismatches at once. The findings must
     * come back at the severity their rule gave them, never at Error.
     */
    #[Test]
    public function itDoesNotTurnAChannelRedWhenItsDeclaredShapeChanges(): void
    {
        $finding = ViolationFactory::occurrence(SymbolPath::forFile(RelativePath::fromString('src/Legacy.php')));
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $declarations->declare('code-smell.goto#code-smell.goto', ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Callable));

        // The stored entry was captured while the channel was `occurrence`.
        $stage = self::stageOver(self::baselineOf([self::occurrenceEntry($finding, 1)]), $declarations);

        self::assertReportedUntouched($stage->apply([$finding])->violations, $finding);
    }

    private static function duplicationFinding(int|float|null $magnitude, int $line): Violation
    {
        return self::findingOn(
            'duplication.code-duplication',
            'duplication.code-duplication',
            SymbolPath::forFile(RelativePath::fromString('src/Legacy/dup.php')),
            $magnitude,
            $line,
        );
    }

    /**
     * @param list<Violation> $reported
     */
    private static function assertReportedUntouched(array $reported, Violation $expected): void
    {
        self::assertCount(1, $reported);
        self::assertSame($expected->severity, $reported[0]->severity);
        self::assertNotSame(Severity::Error, $reported[0]->severity, 'a fail-safe path must never promote');
        self::assertNull($reported[0]->acceptedLevel);
    }
}
