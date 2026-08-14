<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Baseline\GroupAcceptance;
use Qualimetrix\Core\Observation\WorseDirection;

/**
 * ADR 0017 cumulative rule, tested directly against the primitive rather than
 * through {@see \Qualimetrix\Analysis\Policy\Baseline\Filter\BaselineCeilingStage}'s
 * `Violation`-shaped scaffolding — this is the type `baseline:update` also
 * has to call (ADR 0017), so it must be checkable on its own.
 *
 * The two cases below are chosen to actually distinguish the cumulative rule
 * from rank alignment rather than merely exercise it — a comparison that
 * pairs the shorter vector's members from the worst end agrees with the
 * cumulative rule on nearly everything and disagrees exactly here.
 */
#[CoversClass(GroupAcceptance::class)]
final class GroupAcceptanceTest extends TestCase
{
    /**
     * **The case that killed rank alignment (ADR 0017).** Stored `[40, 100]`
     * on a `higher` channel; the 40-line duplicate is repaired and nothing
     * else changes, so the current group is `[100]`.
     *
     * Aligning from the best end pairs the survivor with the vacated `40`
     * and reports a breach on a symbol nobody touched. Aligning from the
     * worst end, and counting, both accept it — but the next case is where
     * "aligns from the worst end" and "counts" stop agreeing.
     */
    #[Test]
    public function itAcceptsWhenTheBestStoredMemberWasRepaired(): void
    {
        self::assertTrue(GroupAcceptance::magnitudesWithin([100.0], [40.0, 100.0], WorseDirection::Higher));
    }

    /**
     * **The case that actually distinguishes counting from rank alignment.**
     * Stored `[100]`, current `[40, 60]`: every current member is milder than
     * the only stored one, so pairing members from the worst end over the
     * shorter vector compares `60` against `100`, finds nothing worse, and
     * accepts a group that doubled in size.
     *
     * Counting rejects it: at `t = 40` there are two current members at
     * least that bad and only one stored one. This is the case that pins
     * ADR 0017 claim that the cumulative rule subsumes the count condition
     * instead of needing a second bullet for it.
     */
    #[Test]
    public function itRejectsAGroupThatGrewInSizeThoughEveryMemberIsMilderThanTheOnlyStoredOne(): void
    {
        self::assertFalse(GroupAcceptance::magnitudesWithin([40.0, 60.0], [100.0], WorseDirection::Higher));
    }

    /**
     * The `lower` mirror of the repaired-best-member case: on a `lower`
     * channel the best member is the *largest* number, so repairing the
     * class scoring 70 out of a stored `[40, 70]` leaves a current group of
     * `[40]`.
     */
    #[Test]
    public function itAcceptsWhenTheBestStoredMemberWasRepairedOnALowerChannel(): void
    {
        self::assertTrue(GroupAcceptance::magnitudesWithin([40.0], [40.0, 70.0], WorseDirection::Lower));
    }

    /**
     * The `lower` mirror of the fold case: stored `[40]`, current
     * `[55.0, 70.0]` — both members improved over the stored one, and the
     * group still doubled. At `t = 70` there are two current members at
     * least that bad and only one stored one.
     */
    #[Test]
    public function itRejectsALowerChannelGroupThatGrewInSizeThoughEveryMemberImproved(): void
    {
        self::assertFalse(GroupAcceptance::magnitudesWithin([55.0, 70.0], [40.0], WorseDirection::Lower));
    }

    #[Test]
    public function itAcceptsAHigherChannelGroupThatDidNotWorsen(): void
    {
        self::assertTrue(GroupAcceptance::magnitudesWithin([15.0], [15.0], WorseDirection::Higher));
    }

    #[Test]
    public function itRejectsAHigherChannelGroupThatWorsenedByOneStep(): void
    {
        self::assertFalse(GroupAcceptance::magnitudesWithin([16.0], [15.0], WorseDirection::Higher));
    }

    #[Test]
    public function itAcceptsAnOccurrenceCountNoLargerThanStored(): void
    {
        self::assertTrue(GroupAcceptance::countWithin(2, 3));
        self::assertTrue(GroupAcceptance::countWithin(3, 3));
    }

    #[Test]
    public function itRejectsAnOccurrenceCountLargerThanStored(): void
    {
        self::assertFalse(GroupAcceptance::countWithin(4, 3));
    }
}
