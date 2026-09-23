<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation\Observation;

use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterion;

/**
 * A class one layer won while a later-declared layer also matched it, with
 * the criterion that fired on each side — what `architecture.potential-shadow`
 * names as the cause.
 */
final readonly class ShadowedClass
{
    public function __construct(
        public string $fqn,
        public MatchedCriterion $assignedCriterion,
        public MatchedCriterion $shadowedCriterion,
    ) {}
}
