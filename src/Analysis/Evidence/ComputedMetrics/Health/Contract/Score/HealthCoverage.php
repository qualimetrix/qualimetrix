<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score;

use LogicException;

/**
 * What share of its subject a health score was actually computed over.
 *
 * A score whose narrowest input saw a third of the classes is a statement about
 * that third, and publishing it as a statement about the project is the defect
 * this type exists to close (ADR 0062). Two states, and the absent one carries
 * a reason: zero and "no field" are different claims, and a coverage that is
 * undefined must say so rather than render as 0.
 *
 * Scores are published beside their coverage and never damped by it — damping
 * was measured and withdrawn, because cohesion coverage tracks small-class
 * design rather than decay.
 */
final readonly class HealthCoverage
{
    private function __construct(
        public bool $applicable,
        public ?int $measured,
        public ?int $eligible,
        public ?float $ratio,
        public ?CoverageUnit $unit,
        public ?string $basis,
        public ?string $reason,
    ) {}

    /**
     * @param string $basis the `.count` metric the measured number was read from
     */
    public static function over(int $measured, int $eligible, CoverageUnit $unit, string $basis): self
    {
        if ($eligible <= 0) {
            throw new LogicException('An empty population is not a coverage of 0; use notApplicable().');
        }

        // Deliberately unclamped: a ratio above 1 means the input counts
        // symbols the population does not, and a silent clamp would publish a
        // reassuring 100% over a broken pairing.
        return new self(true, $measured, $eligible, $measured / $eligible, $unit, $basis, null);
    }

    public static function notApplicable(string $reason): self
    {
        if ($reason === '') {
            throw new LogicException('An unstated reason is the silent absence this type replaces.');
        }

        return new self(false, null, null, null, null, null, $reason);
    }
}
