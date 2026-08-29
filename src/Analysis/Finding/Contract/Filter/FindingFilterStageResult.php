<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Filter;

use Qualimetrix\Analysis\Finding\Contract\Finding;

/**
 * What one stage did to the finding list.
 *
 * **Why removals are carried rather than counted.** A transforming stage can
 * change a finding without dropping it, so `count($before) - count($after)`
 * stops being the number of findings a stage removed the moment any stage
 * promotes rather than filters. Carrying the removed findings makes the
 * per-stage counter exact by construction, and it is what suppression
 * reporting already needs: the suppressed findings themselves, not their
 * number.
 */
final readonly class FindingFilterStageResult
{
    /**
     * @param list<Finding> $findings what the next stage receives, in the order it was given
     * @param list<Finding> $removed what this stage took out, in the same order
     */
    public function __construct(
        public FindingFilterStage $stage,
        public array $findings,
        public array $removed,
    ) {}

    public function removedCount(): int
    {
        return \count($this->removed);
    }
}
