<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline\Filter;

use Qualimetrix\Baseline\BaselineEntry;
use Qualimetrix\Baseline\InertBaselineEntry;
use Qualimetrix\Core\Violation\Filter\ViolationFilterStageResult;

/**
 * Everything {@see BaselineCeilingStage::judgeAll()} learns from one pass
 * over one list of violations: the filtered/promoted result, the entries
 * whose identity was absent from that same list, and the entries the loader could
 * not apply at all, as ADR 0017 requires.
 *
 * **Why this exists instead of a second method call.** Staleness is "absent
 * from the set the ceiling measured", and the set the ceiling measured is
 * whatever it was just handed — so answering it from anything but the exact
 * list {@see BaselineCeilingStage::apply()} would judge is how a caller ends
 * up asking two questions of two different sets that only agree by
 * accident. Bundling the answer into the one call that does the judging
 * makes the two disagreeing impossible rather than merely undocumented.
 *
 * **Why it lives in `Baseline` rather than `Core`.** `staleEntries` and
 * `inertEntries` are `list<BaselineEntry>` and `list<InertBaselineEntry>`,
 * and `Core` may not depend on `Baseline` — the same constraint that makes
 * {@see ViolationFilterStageInterface} reach this type through a downcast
 * rather than carrying it directly.
 */
final readonly class CeilingOutcome
{
    /**
     * @param list<BaselineEntry> $staleEntries entries whose identity did not appear in the
     *                                          measured list (ADR 0017); reported, never acted on
     * @param list<InertBaselineEntry> $inertEntries every entry the loader read but could not
     *                                               apply, unconditional on what was measured —
     *                                               `check` names them so a user can act (ADR 0017)
     */
    public function __construct(
        public ViolationFilterStageResult $result,
        public array $staleEntries,
        public array $inertEntries,
    ) {}
}
