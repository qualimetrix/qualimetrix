<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Analysis\Pipeline\AnalysisResult;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\Console\MeasuredAnalysisRun;

/**
 * Everything one run of a baseline command needs from the analysis it just
 * performed.
 *
 * The measured set (§5.5 of the baseline-ceiling plan) is the point of the
 * object, but three other things travel with it because they are facts about
 * *that* run and cannot be recomputed later without risking disagreement:
 *
 * - the **scope**, in the project-relative form a baseline file records, so
 *   the scope guard of §5.7 compares two spellings of the same idea;
 * - the **project root**, which {@see \Qualimetrix\Baseline\BaselineWriter}
 *   needs to make `file:` keys portable;
 * - the **analysis result**, because `baseline:explain` reads the run's
 *   `@qmx-threshold` overrides off it, and a second analysis to fetch them
 *   could disagree with the first.
 */
final readonly class BaselineRunContext
{
    /**
     * @param list<string> $scope the analysed paths, project-relative where possible —
     *                            what a baseline file records and what the scope guard
     *                            compares against
     */
    public function __construct(
        public MeasuredAnalysisRun $run,
        public array $scope,
        public AbsolutePath $projectRoot,
    ) {}

    /**
     * The measured set: the findings every baseline operation reads (§5.5).
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
