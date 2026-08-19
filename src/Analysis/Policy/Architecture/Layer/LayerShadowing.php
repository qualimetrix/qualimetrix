<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

/**
 * Decides which of the layers a class matched are worth reporting as shadowed.
 *
 * {@see LayerRegistry::resolveAll()} returns every matching layer in
 * declaration order; the first one wins the class and the rest are shadowed
 * *for that class*. Being shadowed is not by itself a defect — first match wins
 * is the declared resolution mechanism, and declaring narrow layers before
 * broad ones (up to a final `**` catch-all) is the documented idiom. The defect
 * is the inverse: a layer that is MORE specific than the one that beat it, and
 * therefore can never win in its own area.
 *
 * The verdict compares the two criteria that actually fired — the one that won
 * the class and the one the shadowed layer matched it with — because layers
 * carry several criteria and only the firing pair explains this class. Only
 * namespace subtrees are comparable ({@see PatternScope}); an undecidable pair
 * stays reported, since a false alarm costs a configuration review while a
 * missed shadow costs a layer that silently owns nothing.
 *
 * @internal Consumed by {@see \Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule}.
 */
final class LayerShadowing
{
    /**
     * @param list<LayerMatch> $matches As returned by {@see LayerRegistry::resolveAll()}:
     *                                  declaration order, first entry assigned.
     *
     * @return list<LayerMatch> The shadowed matches that indicate a declaration
     *                          defect, in declaration order.
     */
    public static function reportableShadows(array $matches): array
    {
        $assigned = array_shift($matches);
        if ($assigned === null) {
            return [];
        }

        $assignedCriterion = $assigned->primaryCriterion();

        return array_values(array_filter(
            $matches,
            static fn(LayerMatch $shadowed): bool => !self::isStrictlyMoreSpecific(
                $assignedCriterion,
                $shadowed->primaryCriterion(),
            ),
        ));
    }

    private static function isStrictlyMoreSpecific(MatchedCriterion $assigned, MatchedCriterion $shadowed): bool
    {
        $assignedScope = PatternScope::fromCriterion($assigned);
        $shadowedScope = PatternScope::fromCriterion($shadowed);

        return $assignedScope !== null
            && $shadowedScope !== null
            && $shadowedScope->strictlyContains($assignedScope);
    }
}
