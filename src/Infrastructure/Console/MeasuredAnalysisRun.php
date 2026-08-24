<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;

/**
 * The run that produced a measured set, alongside the set itself
 * ({@see MeasuredFindingSet::runForPaths()}).
 *
 * `check` only ever needed the findings — {@see MeasuredFindingSet::forPaths()}
 * still returns exactly that. `baseline:explain` needs more from the same
 * run: the `@qmx-threshold` overrides `AnalysisResult` now carries (per the
 * ADR 0017) are a second source of boundary information, and
 * they come from nowhere but the run that produced the measured set — a
 * second, independent analysis would not be guaranteed to agree with the
 * first. Bundling the two here is what lets a caller read both without
 * running the analysis twice or reaching past the measured-set seam to get
 * at the result it was built from.
 */
final readonly class MeasuredAnalysisRun
{
    /**
     * @param list<Finding> $findings the measured set ADR 0017 defines — the same list
     *                                {@see MeasuredFindingSet::forPaths()} returns
     */
    public function __construct(
        public AnalysisResult $result,
        public array $findings,
    ) {}
}
