<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Baseline\BaselineEntry;
use Qualimetrix\Core\Violation\Filter\ViolationFilterStage;
use Qualimetrix\Core\Violation\Violation;

/**
 * What the violation pipeline produced, and what each of its stages removed.
 *
 * **Two lists of findings, and the difference between them is the point.**
 * `violations` is what the user is shown — everything the stages left,
 * including any report narrowing. `measuredViolations` is the set a baseline
 * measures (§5.5 of the baseline-ceiling plan): the input to the baseline
 * stage, after `@qmx-ignore` and the exclusions and before git scope. Every
 * baseline operation reads the second one, capture included; feeding capture
 * the raw analysis output is what let a run record entries for findings the
 * same run had already suppressed.
 *
 * Removals are carried per stage rather than as a set of named counters:
 * a transforming stage can rewrite a finding without dropping it, so a
 * counter derived from list sizes stops being a removal count the moment
 * the baseline promotes rather than filters.
 */
final readonly class ViolationFilterResult
{
    /**
     * @param list<Violation> $violations what the run reports, after every stage
     * @param list<Violation> $measuredViolations the set a baseline measures — the baseline stage's input
     * @param array<string, list<Violation>> $removedByStage what each stage removed, keyed by {@see ViolationFilterStage}'s value
     * @param list<BaselineEntry> $staleEntries entries whose identity the measured set did not hold (§5.7);
     *                                          reported, never acted on
     */
    public function __construct(
        public array $violations,
        public array $measuredViolations = [],
        public array $removedByStage = [],
        public array $staleEntries = [],
    ) {}

    /**
     * What the given stage took out of the run, in the order it saw it.
     *
     * @return list<Violation>
     */
    public function removedBy(ViolationFilterStage $stage): array
    {
        return $this->removedByStage[$stage->value] ?? [];
    }

    public function removedCountBy(ViolationFilterStage $stage): int
    {
        return \count($this->removedBy($stage));
    }

    public function staleEntryCount(): int
    {
        return \count($this->staleEntries);
    }
}
