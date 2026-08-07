<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Violation\Filter;

use Qualimetrix\Core\Violation\Violation;

/**
 * What one stage did to the violation list.
 *
 * **Why removals are carried rather than counted.** A transforming stage can
 * change a violation without dropping it, so `count($before) - count($after)`
 * stops being the number of findings a stage removed the moment any stage
 * promotes rather than filters. Carrying the removed findings makes the
 * per-stage counter exact by construction, and it is what suppression
 * reporting already needs: the suppressed findings themselves, not their
 * number.
 */
final readonly class ViolationFilterStageResult
{
    /**
     * @param list<Violation> $violations what the next stage receives, in the order it was given
     * @param list<Violation> $removed what this stage took out, in the same order
     */
    public function __construct(
        public ViolationFilterStage $stage,
        public array $violations,
        public array $removed,
    ) {}

    public function removedCount(): int
    {
        return \count($this->removed);
    }
}
