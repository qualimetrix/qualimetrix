<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract\Directive;

use LogicException;

/**
 * What each authored `@qmx-threshold` did in one run.
 *
 * A threshold directive has nothing observable to say for itself: no rule
 * reports the boundary it decided with, and the rule layer has no single
 * notion of a boundary to ask about. Only the **difference in outcome** is
 * observable, so the answer is produced by re-executing the rules with the
 * directive removed and comparing what they produced. One execution per
 * authored directive, on the context the run already prepared.
 *
 * **What this does not measure.** The directive's effect on the parsing of
 * itself. `InlineDirectiveValidator` reads the policy's own copy of the
 * override map, which no counterfactual touches, so its diagnostics are
 * identical on every pass and never enter the difference. An annotation that
 * is malformed, unresolvable or unsupported is answered by the
 * `annotation.*` channels, and answering it again here would judge one mistake
 * twice.
 */
interface ThresholdDirectiveAuditInterface
{
    /**
     * @throws LogicException when re-executing the rules on the unchanged context does not
     *                        reproduce the baseline. That is a statement about shared state in
     *                        the rules, not about any directive, and it invalidates every
     *                        verdict the sweep would otherwise return
     *
     * @return list<DirectiveVerdict> one per authored site, in file and line order
     */
    public function verdicts(ThresholdDirectiveAuditInput $input): array;
}
