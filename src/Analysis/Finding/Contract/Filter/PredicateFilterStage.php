<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Filter;

use Qualimetrix\Analysis\Finding\Contract\Violation;

/**
 * Runs a per-violation predicate as a pipeline stage.
 *
 * The four exclusion and suppression filters stay predicates — they judge one
 * finding at a time and either keep it or drop it — and this adapter is what
 * lets the pipeline hold them in the same ordered list as the transforming
 * baseline stage. Rewriting them to consume lists would buy nothing and would
 * make each of them responsible for preserving order and collecting removals.
 */
final readonly class PredicateFilterStage implements ViolationFilterStageInterface
{
    public function __construct(
        private ViolationFilterStage $stage,
        private ViolationFilterInterface $filter,
    ) {}

    public function stage(): ViolationFilterStage
    {
        return $this->stage;
    }

    public function apply(array $violations): ViolationFilterStageResult
    {
        $kept = [];
        $removed = [];

        foreach ($violations as $violation) {
            if ($this->filter->shouldInclude($violation)) {
                $kept[] = $violation;
            } else {
                $removed[] = $violation;
            }
        }

        return new ViolationFilterStageResult($this->stage, $kept, $removed);
    }
}
