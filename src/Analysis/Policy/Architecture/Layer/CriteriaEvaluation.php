<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

/**
 * The outcome of walking one criterion spec against one class: which kinds
 * fired, and which kinds could not be answered at all.
 *
 * Produced by {@see LayerCriteriaMatcher::evaluate()} for the positive
 * ({@see MembershipSpec}) and the exclude ({@see ExcludeSpec}) side alike.
 * {@see outcome()} is the single place where a {@see MatchMode} turns the two
 * lists into one verdict — positive membership, exclusion, and template
 * observation all ask it, so a mode rule cannot hold on one side of the slice
 * and not the other. That divergence is exactly what let template observation
 * and runtime matching disagree about non-pattern criteria.
 */
final readonly class CriteriaEvaluation
{
    /**
     * @param list<MatchedCriterion> $matched Descriptors of the kinds that
     *                                        fired, in declaration order: at
     *                                        most one per kind.
     * @param list<MatchedCriterionKind> $undecidable Kinds the class declared
     *                                                a criterion for and the
     *                                                run has no facts to
     *                                                answer — see
     *                                                {@see CriterionOutcome::Undecidable}.
     *                                                Disjoint from
     *                                                {@see $matched} by
     *                                                construction: a kind that
     *                                                fired is answered.
     */
    public function __construct(
        public array $matched,
        public array $undecidable,
    ) {}

    /**
     * Combines the per-kind outcomes under the spec's own mode, three-valued.
     *
     * {@see MatchMode::Any} is Kleene OR: one firing kind decides the spec
     * regardless of what the undecidable ones would have said, and only a run
     * that decided every declared kind may answer {@see CriterionOutcome::DoesNotMatch}.
     * {@see MatchMode::All} is Kleene AND, mirrored: one kind that definitively
     * did not fire decides, and every declared kind must fire before the spec
     * may answer {@see CriterionOutcome::Matches}.
     *
     * @param int $declaredKinds Number of criterion kinds the spec declares a
     *                           non-empty list for, per
     *                           {@see LayerCriteriaMatcher::declaredKindCount()}.
     *                           Kinds left empty are trivially satisfied under
     *                           {@see MatchMode::All} and contribute to neither
     *                           list.
     */
    public function outcome(MatchMode $mode, int $declaredKinds): CriterionOutcome
    {
        if ($mode === MatchMode::Any) {
            if ($this->matched !== []) {
                return CriterionOutcome::Matches;
            }

            return $this->undecidable === [] ? CriterionOutcome::DoesNotMatch : CriterionOutcome::Undecidable;
        }

        $decided = \count($this->matched);
        if ($decided === $declaredKinds) {
            return CriterionOutcome::Matches;
        }

        // Every declared kind is either a hit or unanswerable: the ones left
        // would decide the spec, and the run cannot say which way.
        if ($this->undecidable !== [] && $decided + \count($this->undecidable) === $declaredKinds) {
            return CriterionOutcome::Undecidable;
        }

        return CriterionOutcome::DoesNotMatch;
    }
}
