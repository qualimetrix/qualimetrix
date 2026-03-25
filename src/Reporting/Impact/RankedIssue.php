<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Impact;

use Qualimetrix\Core\Violation\Violation;

/**
 * A violation ranked by estimated refactoring impact.
 *
 * Combines ClassRank (how central the class is in the dependency graph),
 * severity weight, and remediation time into a single impact score.
 */
final readonly class RankedIssue
{
    public function __construct(
        public Violation $violation,
        public float $impactScore,
        public ?float $classRank,
        public int $debtMinutes,
        public int $severityWeight,
    ) {}
}
