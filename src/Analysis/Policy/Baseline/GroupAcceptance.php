<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use Qualimetrix\Core\Observation\WorseDirection;

/**
 * ADR 0017 acceptance rule, addressable on its own so `baseline:update` can
 * apply the identical test rather than defining monotonicity a second time —
 * ADR 0017 states the requirement: "acceptance is already 'no level of severity
 * holds more members than before', which is precisely 'not more permissive'
 * at group level, so `update` needs no second definition of the same idea."
 *
 * **It counts members; it never pairs them.** A rank comparison has an end to
 * align from, and each end is wrong in one direction. Stored `[100, 40]` on a
 * `higher` channel with the 40-line duplicate deleted and nothing else
 * touched: aligning from the best end reads `100` against `40` and reports a
 * breach on a symbol nobody touched — the tool answering a pure repair with a
 * red build. Aligning from the worst end assumes the opposite. Counting
 * assumes neither: for every value `t`, the number of current members at
 * least as bad as `t` must not exceed the number of stored members at least
 * as bad as `t` (ADR 0017). The cumulative form is
 * provably equivalent to worst-end alignment and additionally subsumes the
 * count condition, which is why {@see countWithin()} is the same rule with
 * the severity axis collapsed rather than a second mechanism.
 */
final class GroupAcceptance
{
    /**
     * The cumulative rule of ADR 0017, evaluated at every level the current group
     * supplies. Only those levels need checking: the test can only fail at a
     * level some current member actually reaches.
     *
     * @param list<float> $current
     * @param list<float> $stored
     */
    public static function magnitudesWithin(array $current, array $stored, WorseDirection $direction): bool
    {
        foreach ($current as $level) {
            $currentAtLevel = self::countAtLeastAsBadAs($current, $level, $direction);
            $storedAtLevel = self::countAtLeastAsBadAs($stored, $level, $direction);

            if ($currentAtLevel > $storedAtLevel) {
                return false;
            }
        }

        return true;
    }

    /**
     * The `occurrence` degeneracy: one axis, no magnitudes, so the group must
     * hold no more members than `count`. Not a second mechanism — the same
     * cumulative statement with the severity axis collapsed, since evaluated
     * at the least-bad current magnitude the cumulative rule's left side is
     * the whole current group.
     */
    public static function countWithin(int $currentCount, int $storedCount): bool
    {
        return $currentCount <= $storedCount;
    }

    /**
     * How many of these magnitudes are at least as bad as `$level`.
     *
     * "At least as bad" is the negation of "the level is worse than this
     * magnitude", so the direction's own operator answers it and no sign
     * handling is re-derived here. The epsilon stays at its `0.0` default:
     * both sides are already normalised to six decimal places, which is what
     * earns the zero.
     *
     * @param list<float> $magnitudes
     */
    private static function countAtLeastAsBadAs(array $magnitudes, float $level, WorseDirection $direction): int
    {
        $count = 0;

        foreach ($magnitudes as $magnitude) {
            if (!$direction->isWorse($level, $magnitude)) {
                ++$count;
            }
        }

        return $count;
    }
}
