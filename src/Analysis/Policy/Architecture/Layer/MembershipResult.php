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
 * caught the class — the diagnostic specificity edge case from Phase 2
 * direction 1.
 *
 * Modelled as a single VO with static factories rather than a sealed
 * hierarchy: the field count is small and the additional indirection adds no
 * clarity. The {@see matched} flag is the discriminant between membership and
 * non-membership; the {@see matchedCriteria} list is empty on both
 * non-matching variants.
 *
 * There are three variants, not two, and the third one changes nothing about
 * membership: {@see excluded()} is a NoMatch whose cause is a firing
 * `exclude:` clause rather than a positive criterion that did not fire. Every
 * consumer that reads {@see matched} sees the two variants it always saw. The
 * distinction exists because the two are indistinguishable from the outside
 * otherwise, and `architecture.unmatched-exclude` is exactly the question
 * "did this clause ever cause the difference".
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

    public function isExcluded(): bool
    {
        return $this->excluded;
    }
}
