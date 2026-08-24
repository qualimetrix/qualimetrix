<?php

namespace Corpus\Annotations;

class Directives
{
    /**
     * @qmx-ignore no.such.rule — names a rule that does not exist
     */
    public function unresolved(): void
    {
    }

    /**
     * @qmx-threshold annotation.directive warning=1 error=2 — retunes a rule that declares no threshold
     */
    public function unsupported(): void
    {
    }

    /**
     * @qmx-threshold complexity.cyclomatic warning=notanumber — unparseable threshold value
     */
    public function invalid(): void
    {
    }

    /**
     * @qmx-ignore code-smell.eval — suppresses a finding that is not here
     */
    public function unused(): void
    {
    }

    /**
     * A name at equal Levenshtein distance from two channels of this same
     * family, so the "did you mean" list has a tie to break. It breaks it by
     * the order the channel universe yields, which is the order producers are
     * registered — so this fixture is what makes that order observable to the
     * gate.
     *
     * @qmx-ignore annotation.unressed-directive -- equidistant from unresolved and unused
     */
    public function equidistant(): void
    {
    }

    /**
     * The same tie, in a different family: three edits from both
     * `design.return-type-coverage` and `design.property-type-coverage`
     * (ADR 0030's split of `design.type-coverage`), so the "did you mean"
     * order between them is observable here too. Deliberately not addable
     * before the reference already knew the three post-split names — see the
     * Ш4c entry in docs/internal/plans/rule-vocabulary/PLAN.md.
     *
     * @qmx-ignore design.repert-type-coverage -- equidistant from return and property
     */
    public function equidistantAcrossFamilies(): void
    {
    }
}
