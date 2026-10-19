<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

use LogicException;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContext\ClassContext;

/**
 * Stateless evaluator that walks the six criterion kinds (patterns,
 * suffix, attributes, member attributes, implements, extends) against a {@see ClassContext}
 * and returns a {@see CriteriaEvaluation}.
 *
 * Shared between positive ({@see MembershipSpec}) and exclude
 * ({@see ExcludeSpec}) evaluation, and between runtime membership
 * ({@see LayerDefinition::matches()}) and template observation
 * ({@see \Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\TupleExtractor}).
 * The mode-combining rules do not differ per call site either: they live once,
 * in {@see CriteriaEvaluation::outcome()}. A second copy of this predicate is
 * how observation and matching came to disagree about non-pattern criteria
 * while each looked right on its own.
 *
 * Lives next to {@see LayerDefinition} because it implements the
 * criterion-walking primitive that {@see LayerDefinition::matches()}
 * orchestrates. Architecture patterns retain their own capture-aware DSL and
 * therefore compile through {@see CapturePattern}, not the Core selector
 * language.
 *
 * @internal Consumed by {@see LayerDefinition} and
 * {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\TupleExtractor}.
 */
final class LayerCriteriaMatcher
{
    /**
     * Walks the six criterion kinds against the class context and returns
     * both halves of the answer: the matched-criterion descriptors (in
     * declaration order: patterns, suffix, attributes, member attributes, implements, extends)
     * and the declared kinds this run has no facts to decide. Empty/missing
     * criterion kinds appear in neither list.
     *
     * **Which kinds can be undecidable, and why exactly those.** `patterns`
     * and `suffix` are derived from the FQN the caller already holds, so they
     * are always decided. `attributes` is decided iff the run analysed this
     * symbol's own declaration — attributes sit on the class itself and reach
     * no further. `implements` and `extends` read a transitive closure, so
     * they are decided only when the closure THEY read was not cut: a
     * truncated chain can still PROVE a hit (the evidence found stands), but it
     * cannot prove the absence of one. `extends` reads the parent-class chain
     * alone, since no interface declares a parent class; `implements` reads
     * that chain and the interfaces above it.
     *
     * @param list<string> $patterns Patterns exactly as the user wrote them.
     * @param list<string> $suffix
     * @param list<string> $attributes
     * @param list<string> $memberAttributes
     * @param list<string> $implements
     * @param list<string> $extends
     */
    public static function evaluate(
        ClassContext $context,
        array $patterns,
        array $suffix,
        array $attributes,
        array $memberAttributes,
        array $implements,
        array $extends,
    ): CriteriaEvaluation {
        self::refuseUnbackedCriteria($context, $attributes, $memberAttributes, $implements, $extends);

        $parentChainKnown = $context->parentChainKnown()
            && ($context->implicitStringableKnown || !\in_array('Stringable', $extends, true));
        $interfacesKnown = $context->interfacesKnown()
            && ($context->implicitStringableKnown || !\in_array('Stringable', $implements, true));

        /** @var list<array{0: ?MatchedCriterion, 1: list<string>, 2: bool, 3: MatchedCriterionKind}> $kinds */
        $kinds = [
            [self::matchPatterns($context, $patterns), $patterns, true, MatchedCriterionKind::Pattern],
            [self::matchSuffix($context, $suffix), $suffix, true, MatchedCriterionKind::Suffix],
            [self::matchAttributes($context, $attributes), $attributes, $context->declarationAnalysed, MatchedCriterionKind::Attribute],
            [self::matchMemberAttributes($context, $memberAttributes), $memberAttributes, $context->declarationAnalysed, MatchedCriterionKind::MemberAttribute],
            [self::matchImplements($context, $implements), $implements, $interfacesKnown, MatchedCriterionKind::Implements],
            [self::matchExtends($context, $extends), $extends, $parentChainKnown, MatchedCriterionKind::Extends],
        ];

        $matched = [];
        $undecidable = [];
        foreach ($kinds as [$criterion, $declared, $decidable, $kind]) {
            if ($criterion !== null) {
                $matched[] = $criterion;

                continue;
            }

            if ($declared !== [] && !$decidable) {
                $undecidable[] = $kind;
            }
        }

        return new CriteriaEvaluation($matched, $undecidable);
    }

    /**
     * Refuses the four criteria that can only be answered from a dependency
     * graph when the context was built without one.
     *
     * Such a context carries three empty lists, which read as "this class has
     * no parents, no interfaces, no attributes" — a plausible answer that no
     * caller can tell from silence. That is how these criteria came to be
     * evaluated during template expansion against a factory nothing had bound
     * yet, and nothing anywhere went red. Patterns and suffix are derived from
     * the FQN alone and stay answerable.
     *
     * @param list<string> $attributes
     * @param list<string> $memberAttributes
     * @param list<string> $implements
     * @param list<string> $extends
     */
    public static function refuseUnbackedCriteria(
        ClassContext $context,
        array $attributes,
        array $memberAttributes,
        array $implements,
        array $extends,
    ): void {
        if ($context->graphBacked) {
            return;
        }

        $declared = [];
        if ($attributes !== []) {
            $declared[] = 'attributes';
        }
        if ($memberAttributes !== []) {
            $declared[] = 'member_attributes';
        }
        if ($implements !== []) {
            $declared[] = 'implements';
        }
        if ($extends !== []) {
            $declared[] = 'extends';
        }

        if ($declared === []) {
            return;
        }

        throw new LogicException(\sprintf(
            'Layer criteria %s were evaluated for "%s" against a ClassContext built without a dependency graph. '
            . 'Bind the graph to the ClassContextFactory before anything reads a context from it.',
            implode(', ', $declared),
            $context->fqn,
        ));
    }

    /**
     * Counts criterion kinds that actually declare entries (non-empty
     * lists). Used by {@see MatchMode::All} to enforce "every declared
     * kind must match" — empty kinds are trivially satisfied and excluded
     * from both the declared count and the matched count.
     *
     * @param list<string> $patterns
     * @param list<string> $suffix
     * @param list<string> $attributes
     * @param list<string> $memberAttributes
     * @param list<string> $implements
     * @param list<string> $extends
     */
    public static function declaredKindCount(
        array $patterns,
        array $suffix,
        array $attributes,
        array $memberAttributes,
        array $implements,
        array $extends,
    ): int {
        $count = 0;
        if ($patterns !== []) {
            $count++;
        }
        if ($suffix !== []) {
            $count++;
        }
        if ($attributes !== []) {
            $count++;
        }
        if ($memberAttributes !== []) {
            $count++;
        }
        if ($implements !== []) {
            $count++;
        }
        if ($extends !== []) {
            $count++;
        }

        return $count;
    }

    /**
     * @param list<string> $patterns
     */
    private static function matchPatterns(ClassContext $context, array $patterns): ?MatchedCriterion
    {
        foreach ($patterns as $pattern) {
            if (CapturePattern::matches($pattern, $context->fqn)) {
                return new MatchedCriterion(MatchedCriterionKind::Pattern, $pattern);
            }
        }

        return null;
    }

    /**
     * @param list<string> $suffix
     */
    private static function matchSuffix(ClassContext $context, array $suffix): ?MatchedCriterion
    {
        if ($suffix === [] || $context->shortName === '') {
            return null;
        }

        foreach ($suffix as $candidate) {
            if (str_ends_with($context->shortName, $candidate)) {
                return new MatchedCriterion(MatchedCriterionKind::Suffix, $candidate);
            }
        }

        return null;
    }

    /**
     * @param list<string> $attributes
     */
    private static function matchAttributes(ClassContext $context, array $attributes): ?MatchedCriterion
    {
        return self::matchFromSet($attributes, $context->attributeFqnSet, MatchedCriterionKind::Attribute);
    }

    /**
     * @param list<string> $memberAttributes
     */
    private static function matchMemberAttributes(ClassContext $context, array $memberAttributes): ?MatchedCriterion
    {
        return self::matchFromSet($memberAttributes, $context->memberAttributeFqnSet, MatchedCriterionKind::MemberAttribute);
    }

    /**
     * @param list<string> $implements
     */
    private static function matchImplements(ClassContext $context, array $implements): ?MatchedCriterion
    {
        return self::matchFromSet($implements, $context->interfaceSet, MatchedCriterionKind::Implements);
    }

    /**
     * @param list<string> $extends
     */
    private static function matchExtends(ClassContext $context, array $extends): ?MatchedCriterion
    {
        return self::matchFromSet($extends, $context->parentClassSet, MatchedCriterionKind::Extends);
    }

    /**
     * @param list<string> $declared
     * @param array<string, true> $available
     */
    private static function matchFromSet(
        array $declared,
        array $available,
        MatchedCriterionKind $kind,
    ): ?MatchedCriterion {
        foreach ($declared as $candidate) {
            if (isset($available[$candidate])) {
                return new MatchedCriterion($kind, $candidate);
            }
        }

        return null;
    }
}
