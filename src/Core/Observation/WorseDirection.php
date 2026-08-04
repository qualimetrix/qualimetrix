<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Observation;

/**
 * The direction in which an axis of measured debt gets worse.
 *
 * Carries the two seam formulas that every consumer of a
 * {@see DebtObservation} would otherwise re-derive with its own sign
 * handling: the "more permissive of" operator behind the allowance rule,
 * and the epsilon-aware worseness test behind comparison.
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
     * This is the operator behind the allowance rule: the allowance for an
     * axis is the more permissive of the captured value and the current
     * violation-onset boundary, so the allowance is never stricter than the
     * onset. The result is written to the baseline file, whose byte-stability
     * contract (§6.2) leaves no room for the numeric type to depend on
     * argument order.
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
     * `int`, and `float` the moment either one is, matching how
     * {@see \Qualimetrix\Core\Observation\AxisObservation} normalizes its own
     * numeric fields (negative zero, NaN, infinity) at the same seam.
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
     */
    public function isWorse(int|float $current, int|float $allowance, float $epsilon = 0.0): bool
    {
        return match ($this) {
            self::Higher => $current > $allowance + $epsilon,
            self::Lower => $current < $allowance - $epsilon,
        };
    }

    /**
     * Tests whether $current is better than $reference beyond $epsilon.
     *
     * The mirror of {@see isWorse()}. Both are false inside the epsilon band,
     * which is what makes "not worse and not better" a representable state.
     */
    public function isBetter(int|float $current, int|float $reference, float $epsilon = 0.0): bool
    {
        return match ($this) {
            self::Higher => $current < $reference - $epsilon,
            self::Lower => $current > $reference + $epsilon,
        };
    }
}
