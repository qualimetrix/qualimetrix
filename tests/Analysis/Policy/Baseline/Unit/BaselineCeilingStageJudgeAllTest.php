<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use PHPUnit\Framework\Attributes\CoversClass;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Baseline\Filter\BaselineCeilingStage;
use Qualimetrix\Analysis\Policy\Baseline\Filter\CeilingOutcome;
use Qualimetrix\Analysis\Policy\Baseline\InertBaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\InertEntryReason;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Finding\Support\FindingFactory;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Fixtures\CeilingStageFixtures;

/**
 * {@see BaselineCeilingStage::judgeAll()} is the single call that replaced
 * the old two-surface shape (a filtering `apply()` plus a second,
 * separately-fed staleness lookup): this pins that `apply()` is nothing but
 * `judgeAll()->result`, and that stale and inert entries come back from the
 * same one pass rather than a second call over a second list.
 */
#[CoversClass(BaselineCeilingStage::class)]
#[CoversClass(CeilingOutcome::class)]
final class BaselineCeilingStageJudgeAllTest extends TestCase
{
    use CeilingStageFixtures;

    /**
     * `apply($v)` and `judgeAll($v)->result` are the only two ways to reach a
     * filtered result, and this is the only test asserting they agree —
     * everything else in this suite exercises one or the other, never both
     * side by side.
     */
    #[Test]
    public function itMakesApplyDelegateToJudgeAll(): void
    {
        $recorded = FindingFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15);
        $worsened = FindingFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 16);
        $unbounded = FindingFactory::occurrence(SymbolPath::forFile(RelativePath::fromString('src/Legacy/dup.php')));

        $stage = self::stageOver(self::baselineOf([self::magnitudeEntry($recorded, [15])]));

        $group = [$worsened, $unbounded];

        $viaApply = $stage->apply($group);
        $viaJudgeAll = $stage->judgeAll($group)->result;

        self::assertEquals($viaApply, $viaJudgeAll);
    }

    /**
     * Staleness is computed from `judgeAll()`'s own input — the old shape
     * required its caller to separately pass the same list `apply()` was
     * given, by docblock convention alone. The difference here is that only
     * one list can be supplied at all, because there is only one parameter.
     */
    #[Test]
    public function itReportsAnEntryStaleWhenItsIdentityIsAbsentFromTheJudgedList(): void
    {
        $present = FindingFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15);
        $absentEntry = self::magnitudeEntry(
            FindingFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'other'), 10),
            [10],
        );

        $stage = self::stageOver(self::baselineOf([
            self::magnitudeEntry($present, [15]),
            $absentEntry,
        ]));

        $outcome = $stage->judgeAll([$present]);

        self::assertSame([$absentEntry], $outcome->staleEntries);
    }

    /**
     * `inertEntries` is the route ADR 0017 needs for `check` to name an entry it
     * could not apply — populated by the loader on the {@see \Qualimetrix\Analysis\Policy\Baseline\Baseline}
     * the stage holds, and carried through unconditionally on every call.
     */
    #[Test]
    public function itCarriesTheBaselinesInertEntriesUnconditionally(): void
    {
        $inert = InertBaselineEntry::forRaw(
            'class:App\\Foo\\Bar',
            null,
            InertEntryReason::Malformed,
            'the entries under a symbol key must be a JSON array',
            ['not', 'an', 'object'],
        );

        $stage = self::stageOver(self::baselineOf([], [$inert]));

        self::assertSame([$inert], $stage->judgeAll([])->inertEntries);
        self::assertSame([], $stage->judgeAll([])->result->findings);
    }

    /**
     * A run with nothing to judge still reports both lists: absence of
     * findings is not absence of a baseline to report on.
     */
    #[Test]
    public function itReportsBothListsEmptyWhenTheBaselineHasNeitherStaleNorInertEntries(): void
    {
        $member = FindingFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15);
        $stage = self::stageOver(self::baselineOf([self::magnitudeEntry($member, [15])]));

        $outcome = $stage->judgeAll([$member]);

        self::assertSame([], $outcome->staleEntries);
        self::assertSame([], $outcome->inertEntries);
    }
}
