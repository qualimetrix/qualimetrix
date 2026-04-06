<?php

declare(strict_types=1);

namespace Qualimetrix\Core\ComputedMetric;

/**
 * Canonical health dimension identifiers.
 *
 * Each case corresponds to a computed metric that produces a 0-100 health score.
 * Use `->value` when storing/retrieving from MetricBag or keying arrays.
 * Use `->shortName()` for display labels (e.g., 'complexity', 'cohesion').
 */
enum HealthDimension: string
{
    case Complexity = 'health.complexity';
    case Cohesion = 'health.cohesion';
    case Coupling = 'health.coupling';
    case Typing = 'health.typing';
    case Maintainability = 'health.maintainability';
    case Overall = 'health.overall';

    /**
     * Returns the short name without the 'health.' prefix.
     *
     * Used in report output, array keys, and display labels.
     */
    public function shortName(): string
    {
        return substr($this->value, 7); // len('health.') = 7
    }

    /**
     * Returns all sub-dimensions (everything except Overall).
     *
     * @return list<self>
     */
    public static function subDimensions(): array
    {
        return [
            self::Complexity,
            self::Cohesion,
            self::Coupling,
            self::Typing,
            self::Maintainability,
        ];
    }

    /**
     * Returns all dimensions in canonical order.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            self::Complexity,
            self::Cohesion,
            self::Coupling,
            self::Typing,
            self::Maintainability,
            self::Overall,
        ];
    }
}
