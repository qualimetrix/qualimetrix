<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Filter;

use Qualimetrix\Analysis\Finding\Contract\Finding;

/**
 * Runs a per-finding predicate as a pipeline stage.
 *
 * The four exclusion and suppression filters stay predicates — they judge one
 * finding at a time and either keep it or drop it — and this adapter is what
 * lets the pipeline hold them in the same ordered list as the transforming
 * baseline stage. Rewriting them to consume lists would buy nothing and would
 * make each of them responsible for preserving order and collecting removals.
 */
final readonly class PredicateFilterStage implements FindingFilterStageInterface
{
    public function __construct(
        private FindingFilterStage $stage,
        private FindingFilterInterface $filter,
    ) {}

    public function stage(): FindingFilterStage
    {
        return $this->stage;
    }

    public function apply(array $findings): FindingFilterStageResult
    {
        $kept = [];
        $removed = [];

        foreach ($findings as $finding) {
            if ($this->filter->shouldInclude($finding)) {
                $kept[] = $finding;
            } else {
                $removed[] = $finding;
            }
        }

        return new FindingFilterStageResult($this->stage, $kept, $removed);
    }
}
