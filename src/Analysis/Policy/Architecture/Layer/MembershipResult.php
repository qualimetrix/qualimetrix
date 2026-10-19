<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

use InvalidArgumentException;

/**
 * Outcome of {@see LayerDefinition::matches()}.
 *
 * The Match variant carries a list of {@see MatchedCriterion} descriptors in
 * **declaration order** — the criterion kinds are scanned
 * {@see MatchedCriterionKind::Pattern}, then {@see MatchedCriterionKind::Suffix},
 * {@see MatchedCriterionKind::Attribute}, {@see MatchedCriterionKind::Implements},
 * {@see MatchedCriterionKind::Extends}. Within a kind, list entries are scanned
 * in declared order and the first matching entry is recorded.
 *
 * Under {@see MatchMode::Any} the descriptor list contains every criterion that
 * fired (one per kind). Under {@see MatchMode::All} the list contains the
 * matched entry for every NON-EMPTY criterion (empty criteria are trivially
 * satisfied and not recorded).
 *
 * {@see LayerRegistry::resolveAll()} forwards the list into {@see LayerMatch}
 * so the {@code architecture.layer-violation} and
 * {@code architecture.potential-shadow} messages can report WHICH criterion
 * caught the class.
 *
 * Modelled as a single VO with static factories rather than a sealed
 * hierarchy: the field count is small and the additional indirection adds no
 * clarity. The {@see matched} flag is the discriminant between membership and
 * non-membership; the {@see matchedCriteria} list is empty on both
 * non-matching variants.
 *
 * Beside the plain match and non-match there are three variants, and none of
 * them changes what {@see $matched} says. {@see excluded()} is a non-match
 * whose cause is a firing `exclude:` clause rather than a positive criterion
 * that did not fire; {@see undecided()} is a non-match the run never
 * established; {@see doubtedMatch()} is a match the run could not fully
 * establish, because the `exclude:` clause that would remove it could not be
 * answered. Every consumer that reads {@see $matched} sees membership exactly
 * as a two-valued reader would. The distinctions exist because each is
 * otherwise indistinguishable from the outside, and each answers a question
 * someone asks: `architecture.unmatched-exclude` asks "did this clause ever
 * cause the difference"; `architecture.coverage-gap`,
 * `architecture.doubted-assignment` and `debug:layer-assignment` ask what the
 * run could not decide; `architecture.unreachable-layer` and
 * `architecture.potential-shadow` ask which matches the run established.
 */
final readonly class MembershipResult
{
    /**
     * @param list<MatchedCriterion> $matchedCriteria
     */
    private function __construct(
        public bool $matched,
        public array $matchedCriteria,
        public bool $excluded = false,
        public bool $undecided = false,
    ) {}

    /**
     * Builds a Match result carrying the descriptors of every criterion that
     * fired, in declaration order.
     *
     * @param list<MatchedCriterion> $criteria Non-empty list of matched
     *                                         criterion descriptors.
     *
     * @throws InvalidArgumentException If {@code $criteria} is empty.
     */
    public static function match(array $criteria): self
    {
        if ($criteria === []) {
            throw new InvalidArgumentException(
                'MembershipResult::match() requires at least one matched criterion. '
                . 'Use MembershipResult::noMatch() for the non-matching variant.',
            );
        }

        return new self(true, $criteria);
    }

    public static function noMatch(): self
    {
        return new self(false, []);
    }

    /**
     * A non-match caused by the layer's `exclude:` clause firing after the
     * positive criteria had already succeeded.
     *
     * Membership-wise identical to {@see noMatch()} — {@see $matched} is
     * false and no criterion is carried, because the criteria that matched
     * did not win the class. Only {@see isExcluded()} tells the two apart,
     * and only {@see LayerRegistry::excludedLayers()} asks.
     */
    public static function excluded(): self
    {
        return new self(false, [], true);
    }

    /**
     * A non-match the run could not actually establish: at least one declared
     * positive criterion was {@see CriterionOutcome::Undecidable} and none of
     * the decided ones settled the layer either way.
     *
     * Membership-wise it is {@see noMatch()} — {@see $matched} is false, so no
     * consumer starts counting an unproven class as a member.
     *
     * Read through the {@see $undecided} property rather than a predicate, the
     * way {@see $matched} is; {@see isExcluded()} keeps its method only because
     * every caller it has already spells it that way.
     */
    public static function undecided(): self
    {
        return new self(false, [], undecided: true);
    }

    /**
     * A match whose `exclude:` clause could not be answered.
     *
     * The positive criteria caught the class; whether the clause removes it
     * is unknown. The match stands — withdrawing it would leave the class in
     * no layer and its edges unjudged, a missing answer where a doubtful one
     * was available — and {@see $undecided} carries the doubt to the same
     * readers an unanswered earlier layer reaches.
     *
     * @param list<MatchedCriterion> $criteria
     */
    public static function doubtedMatch(array $criteria): self
    {
        return new self(true, self::match($criteria)->matchedCriteria, undecided: true);
    }

    public function isExcluded(): bool
    {
        return $this->excluded;
    }
}
