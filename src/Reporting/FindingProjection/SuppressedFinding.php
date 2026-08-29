<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection;

use Qualimetrix\Analysis\Finding\Contract\Finding;

/**
 * One finding kept out of the report by one mechanism.
 *
 * The unit the `suppressed` format publishes is mechanism × finding, not
 * finding alone: a finding removed by an exclusion stage may also have been
 * removed by `@qmx-ignore` had the stage not already taken it first, and a
 * finding the per-rule ledger drops is, separately, a candidate for the
 * global exclusion stages that run after it. Counting distinct findings
 * across mechanisms would therefore either double-count or arbitrarily pick
 * one mechanism to credit; publishing the pair instead makes the multiset
 * nature explicit rather than hidden in a total that does not add up.
 */
final readonly class SuppressedFinding
{
    /**
     * @param string $suppressor Who did it, in the vocabulary named for that mechanism
     *                           by {@see SuppressionMechanism}: the producer rule name for
     *                           both ledger halves, the matched pattern for the two global
     *                           exclusion mechanisms, the `file:line` of the directive for
     *                           {@see SuppressionMechanism::Suppression}, the baseline
     *                           entry's description for {@see SuppressionMechanism::Baseline},
     *                           and the git reference for {@see SuppressionMechanism::GitScope}.
     */
    public function __construct(
        public Finding $finding,
        public SuppressionMechanism $mechanism,
        public string $suppressor,
    ) {}
}
