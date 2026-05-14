<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Architecture\Layer;

use InvalidArgumentException;

/**
 * Immutable descriptor of a single membership criterion that matched a class.
 *
 * Returned in the {@see MembershipResult::$matchedCriteria} list and carried
 * forward into {@see LayerMatch::$matchedCriteria}. Two pieces of information:
 *
 * - {@see kind} — which criterion kind fired ({@see MatchedCriterionKind}).
 * - {@see value} — the entry from the layer's criterion list that produced
 *   the match (e.g. {@code 'App\\Service\\**'} for a pattern, {@code 'Repository'}
 *   for a suffix, {@code 'App\\Domain\\AggregateRoot'} for an extends FQN).
 *
 * The {@see describe()} helper renders a human-readable label used by the
 * violation message and the debug command: {@code "pattern \"App\\Service\""}
 * etc.
 */
final readonly class MatchedCriterion
{
    public function __construct(
        public MatchedCriterionKind $kind,
        public string $value,
    ) {
        if ($value === '') {
            throw new InvalidArgumentException(\sprintf(
                'MatchedCriterion %s value must not be empty.',
                $kind->value,
            ));
        }
    }

    /**
     * Renders the criterion as a short label, used in violation messages.
     */
    public function describe(): string
    {
        return $this->kind->value . ' "' . $this->value . '"';
    }
}
