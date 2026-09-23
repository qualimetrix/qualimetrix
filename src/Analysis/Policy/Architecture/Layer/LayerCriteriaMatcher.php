<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

use LogicException;

/**
 * Stateless evaluator that walks the five criterion kinds (patterns,
 * suffix, attributes, implements, extends) against a {@see ClassContext}
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
     * Walks the five criterion kinds against the class context and returns
     * both halves of the answer: the matched-criterion descriptors (in
     * declaration order: patterns, suffix, attributes, implements, extends)
     * and the declared kinds this run has no facts to decide. Empty/missing
     * criterion kinds appear in neither list.
     *
     * **Which kinds can be undecidable, and why exactly those.** `patterns`
     * and `suffix` are derived from the FQN the caller already holds, so they
     * are always decided. `attributes` is decided iff the run analysed this
     * symbol's own declaration — attributes sit on the class itself and reach
     * no further. `implements` and `extends` read a transitive closure, so
     * they are decided only when that closure was not cut: a truncated chain
     * can still PROVE a hit (the evidence found stands), but it cannot prove
     * the absence of one.
     *
     * @param list<string> $patterns Patterns exactly as the user wrote them.
     * @param list<string> $suffix
     * @param list<string> $attributes
     * @param list<string> $implements
     * @param list<string> $extends
     */
    public static function evaluate(
        ClassContext $context,
        array $patterns,
        array $suffix,
        array $attributes,
        array $implements,
        array $extends,
    ): CriteriaEvaluation {
        self::refuseUnbackedCriteria($context, $attributes, $implements, $extends);

        $ancestryComplete = $context->unresolvedDeclarations === [];

        /** @var list<array{0: ?MatchedCriterion, 1: list<string>, 2: bool, 3: MatchedCriterionKind}> $kinds */
        $kinds = [
            [self::matchPatterns($context, $patterns), $patterns, true, MatchedCriterionKind::Pattern],
            [self::matchSuffix($context, $suffix), $suffix, true, MatchedCriterionKind::Suffix],
            [self::matchAttributes($context, $attributes), $attributes, $context->declarationAnalysed, MatchedCriterionKind::Attribute],
            [self::matchImplements($context, $implements), $implements, $ancestryComplete, MatchedCriterionKind::Implements],
            [self::matchExtends($context, $extends), $extends, $ancestryComplete, MatchedCriterionKind::Extends],
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
     * Refuses the three criteria that can only be answered from a dependency
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
     * @param list<string> $implements
     * @param list<string> $extends
     */
    public static function refuseUnbackedCriteria(
        ClassContext $context,
        array $attributes,
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
     * @param list<string> $implements
     * @param list<string> $extends
     */
    public static function declaredKindCount(
        array $patterns,
        array $suffix,
        array $attributes,
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
        if ($attributes === [] || $context->attributeFqnSet === []) {
            return null;
        }

        foreach ($attributes as $attributeFqn) {
            if (isset($context->attributeFqnSet[$attributeFqn])) {
                return new MatchedCriterion(MatchedCriterionKind::Attribute, $attributeFqn);
            }
        }

        return null;
    }

    /**
     * @param list<string> $implements
     */
    private static function matchImplements(ClassContext $context, array $implements): ?MatchedCriterion
    {
        if ($implements === [] || $context->interfaceSet === []) {
            return null;
        }

        foreach ($implements as $interfaceFqn) {
            if (isset($context->interfaceSet[$interfaceFqn])) {
                return new MatchedCriterion(MatchedCriterionKind::Implements, $interfaceFqn);
            }
        }

        return null;
    }

    /**
     * @param list<string> $extends
     */
    private static function matchExtends(ClassContext $context, array $extends): ?MatchedCriterion
    {
        if ($extends === [] || $context->parentClassSet === []) {
            return null;
        }

        foreach ($extends as $parentFqn) {
            if (isset($context->parentClassSet[$parentFqn])) {
                return new MatchedCriterion(MatchedCriterionKind::Extends, $parentFqn);
            }
        }

        return null;
    }
}
