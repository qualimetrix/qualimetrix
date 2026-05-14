<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Architecture\Layer;

use Qualimetrix\Core\Util\NamespaceMatcher;

/**
 * Stateless evaluator that walks the five criterion kinds (patterns,
 * suffix, attributes, implements, extends) against a {@see ClassContext}
 * and returns the matched-criterion descriptor list.
 *
 * Shared between positive ({@see MembershipSpec}) and exclude
 * ({@see ExcludeSpec}) evaluation in {@see LayerDefinition::matches()}.
 * The underlying matching semantics are identical for both specs; only
 * the mode-combining rules (when {@see MatchMode::All} requires every
 * declared kind to fire) differ at the call site.
 *
 * Lives next to {@see LayerDefinition} because it implements the
 * criterion-walking primitive that {@see LayerDefinition::matches()}
 * orchestrates. Per-pattern FQN matching is delegated to
 * {@see NamespaceMatcher::matchesSingle()} so this class shares a single
 * source of truth with the wider namespace-matching utility.
 *
 * @internal Consumed by {@see LayerDefinition}.
 */
final class LayerCriteriaMatcher
{
    /**
     * Walks the five criterion kinds against the class context and returns
     * the matched-criterion descriptor list (in declaration order: patterns,
     * suffix, attributes, implements, extends). Empty/missing criterion
     * kinds produce no descriptor.
     *
     * @param list<string> $normalizedPatterns Patterns with trailing
     *                                         backslashes stripped (used
     *                                         for the actual
     *                                         {@see NamespaceMatcher}
     *                                         lookup).
     * @param list<string> $rawPatterns Original pattern list used to label
     *                                  the resulting
     *                                  {@see MatchedCriterion} so
     *                                  diagnostics reflect what the user
     *                                  wrote.
     * @param list<string> $suffix
     * @param list<string> $attributes
     * @param list<string> $implements
     * @param list<string> $extends
     *
     * @return list<MatchedCriterion>
     */
    public static function collectMatches(
        ClassContext $context,
        array $normalizedPatterns,
        array $rawPatterns,
        array $suffix,
        array $attributes,
        array $implements,
        array $extends,
    ): array {
        $matches = [
            self::matchPatterns($context, $normalizedPatterns, $rawPatterns),
            self::matchSuffix($context, $suffix),
            self::matchAttributes($context, $attributes),
            self::matchImplements($context, $implements),
            self::matchExtends($context, $extends),
        ];

        return array_values(array_filter(
            $matches,
            static fn(?MatchedCriterion $criterion): bool => $criterion !== null,
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
     * Strips trailing backslashes from each pattern. Required because
     * {@see NamespaceMatcher::matchesSingle()} treats trailing slashes as
     * raw FQN characters, while the user-facing convention is that a
     * trailing slash is purely cosmetic ({@code App\Service\} ≡
     * {@code App\Service}).
     *
     * @param list<string> $patterns
     *
     * @return list<string>
     */
    public static function normalizePatterns(array $patterns): array
    {
        $normalized = [];
        foreach ($patterns as $pattern) {
            $normalized[] = rtrim($pattern, '\\');
        }

        return $normalized;
    }

    /**
     * @param list<string> $normalizedPatterns
     * @param list<string> $rawPatterns
     */
    private static function matchPatterns(ClassContext $context, array $normalizedPatterns, array $rawPatterns): ?MatchedCriterion
    {
        if ($normalizedPatterns === []) {
            return null;
        }

        foreach ($normalizedPatterns as $index => $pattern) {
            if (NamespaceMatcher::matchesSingle($pattern, $context->fqn)) {
                return new MatchedCriterion(
                    MatchedCriterionKind::Pattern,
                    $rawPatterns[$index],
                );
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
        if ($attributes === [] || $context->attributeFqns === []) {
            return null;
        }

        $haystack = array_fill_keys($context->attributeFqns, true);
        foreach ($attributes as $attributeFqn) {
            if (isset($haystack[$attributeFqn])) {
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
        if ($implements === [] || $context->interfaces === []) {
            return null;
        }

        $haystack = array_fill_keys($context->interfaces, true);
        foreach ($implements as $interfaceFqn) {
            if (isset($haystack[$interfaceFqn])) {
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
        if ($extends === [] || $context->parentClasses === []) {
            return null;
        }

        $haystack = array_fill_keys($context->parentClasses, true);
        foreach ($extends as $parentFqn) {
            if (isset($haystack[$parentFqn])) {
                return new MatchedCriterion(MatchedCriterionKind::Extends, $parentFqn);
            }
        }

        return null;
    }
}
