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
 * There are four variants, not two, and the extra two change nothing about
 * membership: {@see excluded()} is a NoMatch whose cause is a firing
 * `exclude:` clause rather than a positive criterion that did not fire, and
 * {@see undecided()} is a NoMatch the run never established at all. Every
 * consumer that reads {@see matched} sees the two variants it always saw. The
 * distinctions exist because each is otherwise indistinguishable from a plain
 * non-match from the outside, and each answers a question someone asks:
 * `architecture.unmatched-exclude` asks "did this clause ever cause the
 * difference", and `architecture.coverage-gap` asks whether the gap it reports
 * is one the author can close.
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
     * criterion was {@see CriterionOutcome::Undecidable} and none of the
     * decided ones settled the layer either way.
     *
     * Membership-wise it is {@see noMatch()} — {@see $matched} is false, so no
     * consumer starts counting an unproven class as a member. The variant
     * exists because the difference has to reach a reader: an unassigned class
     * whose criteria were all answered is a coverage gap the author can close
     * by declaring a layer, and one whose inheritance chain left the analysed
     * set is a gap no layer declaration will close.
     *
     * Not a third state of {@see $excluded}: an `exclude:` clause that fired is
     * a decision, and one that could not be decided lands here instead.
     *
     * Read through the {@see $undecided} property rather than a predicate, the
     * way {@see $matched} is; {@see isExcluded()} keeps its method only because
     * every caller it has already spells it that way.
     */
    public static function undecided(): self
    {
        return new self(false, [], undecided: true);
    }

    public function isExcluded(): bool
    {
        return $this->excluded;
    }
}
