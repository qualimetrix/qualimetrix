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
     * The same tie, one family over and across a split: a name three edits from
     * `design.type-coverage.return` and three from `design.type-coverage.property`,
     * so the "did you mean" answer has to choose between two channels that
     * different producers registered. `ChannelSuggestionTieTest` measures the
     * distances; only a run of the product shows that the tie reaches a
     * published `message`.
     *
     * @qmx-ignore design.type-coverage.propurn -- equidistant from the return and property channels
     */
    public function equidistantAcrossTheSplit(): void
    {
    }

    /**
     * A pair whose level the channel it names does not declare. The name half
     * resolves, so what is unaddressable here is the pair -- the one shape the
     * level vocabulary added and the corpus did not yet hold.
     *
     * @qmx-ignore duplication.code-duplication:class -- that channel reports at project level only
     */
    public function impossiblePair(): void
    {
    }
}
