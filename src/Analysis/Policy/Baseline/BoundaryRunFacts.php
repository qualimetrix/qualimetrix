<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;

/** Measured evidence for one boundary explanation invocation. */
final readonly class BoundaryRunFacts
{
    /** @param list<Finding> $measuredFindings */
    public function __construct(
        public array $measuredFindings,
        public RunCoverage $coverage,
        public ?MetricRepositoryInterface $symbolLocations,
    ) {}
}
