<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Observation;

/**
 * The direction in which a magnitude gets worse.
 *
 * Carries the two seam formulas that every consumer would otherwise
 * re-derive with its own sign handling: the "more permissive of" operator
 * behind `baseline:update` (§7 of the ratchet-baseline plan), and the
 * epsilon-aware worseness test the group-acceptance decision counts members
 * with (§5.1 of the ratchet-baseline plan).
 */
enum WorseDirection: string
{
    /** Larger values are worse (CCN, CBO, parameter counts, cycle size). */
    case Higher = 'higher';

    /** Smaller values are worse (Maintainability Index, cohesion, health scores). */
    case Lower = 'lower';

    /**
     * Returns the more permissive of two boundaries in this direction.
     *
     * This is the primitive behind `baseline:update` (§7 of the
     * ratchet-baseline plan): a boundary may move only toward stricter,
     * never toward more permissive, so `update` folds the recorded boundary
     * and the current one through this operator rather than overwriting one
     * with the other. The result is written to the baseline file, whose
     * byte-stability contract (§6.2) leaves no room for the numeric type to
     * depend on argument order.
     *
     * `max()`/`min()` alone are not enough: when the two boundaries are
     * numerically equal but differ in type (`10` vs `10.0`), PHP resolves the
     * tie by returning whichever argument came first, so
     * `morePermissive(10, 10.0)` and `morePermissive(10.0, 10)` would
     * disagree on `int` vs `float` for the same allowance. Outside a tie the
     * winning boundary already carries an unambiguous type — it is strictly
     * the larger (or smaller) of the two, regardless of call order — so only
     * the tie needs normalizing. The canonical rule mirrors PHP's own
     * arithmetic promotion: the result is `int` only when both inputs are
     * `int`, and `float` the moment either one is.
     */
    public function morePermissive(int|float $a, int|float $b): int|float
    {
        $winner = match ($this) {
            self::Higher => max($a, $b),
            self::Lower => min($a, $b),
        };

        if ((float) $a === (float) $b) {
            return (\is_int($a) && \is_int($b)) ? (int) $winner : (float) $winner;
        }

        return $winner;
    }

    /**
     * Tests whether $current is worse than $allowance beyond $epsilon.
     *
     * Higher-is-worse: `current > allowance + epsilon`.
     * Lower-is-worse: `current < allowance - epsilon`.
     *
     * Epsilon is a tolerance band around the allowance, never a shift of it:
     * a value inside the band is not worse in either direction.
     *
     * This is the comparison behind §5.1's group acceptance in the
     * ratchet-baseline plan, and it is what "at least as bad as" is measured
     * with there: a member is at least as bad as a level exactly when the
     * level is not worse than it. Acceptance then **counts members per level
     * of severity** — for every value the current group supplies, it must
     * hold no more members at least that bad than the stored group did.
     *
     * It never pairs a current member with a stored one. A rank comparison
     * has an end to align from and each end is wrong in one direction (§5.1,
     * §15): aligning from the best end reports a breach when a group's best
     * member is simply repaired, aligning from the worst end accepts a group
     * that grew. Counting assumes neither.
     */
    public function isWorse(int|float $current, int|float $allowance, float $epsilon = 0.0): bool
    {
        return match ($this) {
            self::Higher => $current > $allowance + $epsilon,
            self::Lower => $current < $allowance - $epsilon,
        };
    }
}
