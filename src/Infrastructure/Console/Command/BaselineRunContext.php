<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Analysis\Pipeline\AnalysisResult;
use Qualimetrix\Baseline\RunScope;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\Console\MeasuredAnalysisRun;

/**
 * Everything one run of a baseline command needs from the analysis it just
 * performed.
 *
 * The measured set (ADR 0017) is the point of the
 * object, but three other things travel with it because they are facts about
 * *that* run and cannot be recomputed later without risking disagreement:
 *
 * - the **scope**, as a {@see RunScope} rather than a bare path list, so the
 *   guard of ADR 0017 asks the object that derived the portable form whether it
 *   covers the recorded one instead of two sides deriving it separately;
 * - the **project root**, which {@see \Qualimetrix\Baseline\BaselineWriter}
 *   needs to make `file:` keys portable;
 * - the **analysis result**, because `baseline:explain` reads the run's
 *   `@qmx-threshold` overrides off it, and a second analysis to fetch them
 *   could disagree with the first.
 */
final readonly class BaselineRunContext
{
    /**
     * @param RunScope $scope the analysed paths in the portable form a baseline file
     *                        records, and the coverage predicate the scope guard reads
     */
    public function __construct(
        public MeasuredAnalysisRun $run,
        public RunScope $scope,
        public AbsolutePath $projectRoot,
    ) {}

    /**
     * The measured set: the findings every baseline operation reads (ADR 0017).
     *
     * @return list<Violation>
     */
    public function violations(): array
    {
        return $this->run->violations;
    }

    public function result(): AnalysisResult
    {
        return $this->run->result;
    }
}
