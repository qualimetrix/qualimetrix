<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

use Qualimetrix\Analysis\Policy\Architecture\Contract\ShadowExemption;

/**
 * Judges established matches in declaration order using the criteria that fired.
 * An unanswered exclude is not an established match and cannot draw a shadow.
 * A repeating pattern receives what the earlier layer leaves before the universal
 * pattern exception is considered; otherwise a non-pattern pair is precedence,
 * whose observed losses remain evidence for overlap and unreachable-layer.
 */
final class LayerShadowing
{
    /** @param list<LayerMatch> $established
     * @return list<LayerShadowVerdict>
     */
    public static function verdicts(array $established): array
    {
        $earlier = array_shift($established);
        if ($earlier === null) {
            return [];
        }
        return array_map(
            static fn(LayerMatch $later): LayerShadowVerdict => new LayerShadowVerdict($earlier, $later, self::exemption($earlier, $later)),
            $established,
        );
    }

    /** @param list<LayerMatch> $established
     * @return list<LayerMatch>
     */
    public static function reportableShadows(array $established): array
    {
        return array_values(array_map(
            static fn(LayerShadowVerdict $verdict): LayerMatch => $verdict->later,
            array_filter(self::verdicts($established), static fn(LayerShadowVerdict $verdict): bool => $verdict->exemption === null),
        ));
    }

    private static function exemption(LayerMatch $earlier, LayerMatch $later): ?ShadowExemption
    {
        $first = $earlier->primaryCriterion();
        $last = $later->primaryCriterion();
        if (self::isStrictlyMoreSpecific($first, $last)) {
            return ShadowExemption::NarrowerDeclaredFirst;
        }
        if (self::receivesWhatItLeaves($earlier, $later)) {
            return ShadowExemption::ReceivesWhatItLeaves;
        }
        if ($earlier->ownsItsPatterns && PatternScope::fromCriterion($first)?->isUniversal() === true) {
            return null;
        }
        return $first->kind === MatchedCriterionKind::Pattern && $last->kind === MatchedCriterionKind::Pattern
            ? null
            : ShadowExemption::NonPatternPrecedence;
    }

    private static function receivesWhatItLeaves(LayerMatch $shadowing, LayerMatch $shadowed): bool
    {
        $left = $shadowing->primaryCriterion();
        $taken = $shadowed->primaryCriterion();

        return !$shadowing->ownsItsPatterns
            && $left->kind === MatchedCriterionKind::Pattern
            && $taken->kind === MatchedCriterionKind::Pattern
            && MembershipSpec::patternIdentity($left->value) === MembershipSpec::patternIdentity($taken->value);
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
