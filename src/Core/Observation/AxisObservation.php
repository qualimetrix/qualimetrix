<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Observation;

use InvalidArgumentException;

/**
 * One measured dimension of a {@see DebtObservation}.
 *
 * An axis carries the *raw* measurement, not a display-rounded one, and the
 * violation-onset boundary that was in force when the measurement was taken
 * — the most permissive boundary at which this symbol emits a violation at
 * all under the current configuration and run.
 *
 * A null {@see $rawValue} is a legal, first-class state and not a shape
 * change: a class with fewer than two public methods has no TCC at all.
 * A null {@see $onsetBoundary} means the axis has no numeric boundary — the
 * finding fires on a predicate rather than on a threshold.
 */
final readonly class AxisObservation
{
    /** Raw measurement, or null when the metric is genuinely unavailable for this symbol. */
    public int|float|null $rawValue;

    /** Violation-onset boundary in force now, or null when the axis has no numeric boundary. */
    public int|float|null $onsetBoundary;

    /** Tolerance band around the allowance; never negative, never NaN or infinite. */
    public float $epsilon;

    public function __construct(
        public string $name,
        int|float|null $rawValue,
        int|float|null $onsetBoundary = null,
        public WorseDirection $worseDirection = WorseDirection::Higher,
        float $epsilon = 0.0,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('AxisObservation name must not be empty.');
        }

        // A name PHP's array-key coercion would treat as an integer (e.g.
        // "10") is rejected here rather than left to surface later: once such
        // a name reaches `DebtObservation::$axes` — a `string => AxisObservation`
        // map keyed by name — PHP silently coerces the key to `int`, so
        // `axisNames()` returns an `int` against its declared `list<string>`
        // and the map itself violates `array<string, AxisObservation>`.
        // Nothing downstream can detect this from the map alone, because a
        // numeric-string axis name and its coerced integer key print
        // identically; the only place to close it is at construction, before
        // the name is ever used as an array key.
        if ((string) (int) $name === $name) {
            throw new InvalidArgumentException(
                \sprintf(
                    'AxisObservation name "%s" would be silently coerced to an integer array key; '
                    . 'axis names must not be strings PHP treats as integers.',
                    $name,
                ),
            );
        }

        $this->rawValue = self::normalize($rawValue, $name, 'rawValue');
        $this->onsetBoundary = self::normalize($onsetBoundary, $name, 'onsetBoundary');

        if (!is_finite($epsilon)) {
            throw new InvalidArgumentException(
                \sprintf('AxisObservation "%s": epsilon must be finite, got %s.', $name, self::describe($epsilon)),
            );
        }

        if ($epsilon < 0.0) {
            throw new InvalidArgumentException(
                \sprintf('AxisObservation "%s": epsilon must not be negative, got %s.', $name, (string) $epsilon),
            );
        }

        // `-0.0 === 0.0` in PHP, so this also normalizes negative zero.
        $this->epsilon = $epsilon === 0.0 ? 0.0 : $epsilon;
    }

    /**
     * Whether this axis carries a measurement. A false answer means the
     * metric was unavailable, and the axis is skipped in comparison rather
     * than treated as an improvement or a regression.
     */
    public function hasValue(): bool
    {
        return $this->rawValue !== null;
    }

    /** Whether this axis has a numeric violation-onset boundary. */
    public function hasOnsetBoundary(): bool
    {
        return $this->onsetBoundary !== null;
    }

    /**
     * Rejects NaN and infinity, and normalizes negative zero.
     *
     * NaN and infinity are invalid both as observations and as serialized
     * values: neither survives a JSON round trip, and NaN silently answers
     * false to every comparison, which would read as "not worse".
     */
    private static function normalize(int|float|null $value, string $axis, string $field): int|float|null
    {
        if ($value === null) {
            return null;
        }

        if (\is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException(
                    \sprintf('AxisObservation "%s": %s must be finite, got %s.', $axis, $field, self::describe($value)),
                );
            }

            if ($value === 0.0) {
                return 0.0;
            }
        }

        return $value;
    }

    private static function describe(float $value): string
    {
        if (is_nan($value)) {
            return 'NAN';
        }

        return $value > 0 ? 'INF' : '-INF';
    }
}
