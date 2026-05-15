<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Processing;

use Qualimetrix\Architecture\Domain\Layer\CapturePattern;
use Qualimetrix\Architecture\Domain\Layer\ClassContext;
use Qualimetrix\Architecture\Domain\Layer\ClassSet;
use Qualimetrix\Architecture\Domain\Layer\ExcludeSpec;
use Qualimetrix\Architecture\Domain\Layer\LayerCriteriaMatcher;
use Qualimetrix\Architecture\Domain\Layer\MatchMode;
use Qualimetrix\Architecture\Domain\Layer\MembershipSpec;
use Qualimetrix\Architecture\Domain\Layer\TemplateLayerDefinition;
use Qualimetrix\Core\Util\NamespaceMatcher;

/**
 * Walks a {@see ClassSet} once and collects the distinct observed binding
 * tuples for a {@see TemplateLayerDefinition}.
 *
 * Extracted from {@see LayerExpansionStage} in Phase 4.1 of the remediation
 * (ADR 0008). The algorithm and its public output (deduplicated list of
 * binding tuples, lex-sorted) are unchanged. Phase 5 of the remediation
 * tightens two semantic gaps:
 *
 * - **M1 (exclude during observation).** The template's {@see ExcludeSpec}
 *   is evaluated AFTER capture binding succeeds, using the substituted
 *   bindings — a class that would be removed from the concrete layer
 *   at runtime is also removed from tuple observation, so excluded classes
 *   do not contribute "phantom" concrete layers.
 *
 * - **M2 Path B (mode-aware non-pattern criteria).** Non-pattern criteria
 *   ({@code suffix}, {@code attributes}, {@code implements}, {@code extends})
 *   now respect the membership's {@see MatchMode}: under
 *   {@see MatchMode::Any} they act as OR alongside the capture-producing
 *   pattern (a class with a binding from the capture pattern is observed
 *   even if none of the non-pattern criteria match); under
 *   {@see MatchMode::All} every declared non-pattern criterion must match
 *   (the previous AND behavior). This aligns template expansion with the
 *   runtime D2 membership semantics implemented by
 *   {@see \Qualimetrix\Architecture\Domain\Layer\LayerDefinition::matches()}.
 *
 * **Capture-producing vs non-capturing criteria (D7 carve-out).** Within
 * {@see MembershipSpec::$patterns}, patterns are classified: a pattern that
 * contains at least one `{var}` placeholder is capture-producing; a plain
 * glob is non-capturing. {@see MembershipSpec::$mode} (`match: any|all`)
 * governs the combination of capture-producing patterns AND (post-M2 Path B)
 * the non-pattern criteria above. Non-capturing patterns continue to act as
 * a pure AND-filter regardless of mode — they describe "where the layer
 * lives" and would never widen membership.
 *
 * **Determinism.** Observed tuples are sorted lexicographically by the
 * template's {@see TemplateLayerDefinition::$variables} order so the result
 * is stable across runs even though `metrics->all()` iteration is
 * parallel-collection-sensitive.
 */
final class TupleExtractor
{
    /**
     * Collects the distinct observed binding tuples for the template, lex-
     * sorted by the template's variable order.
     *
     * @return list<array<string, string>>
     */
    public function collect(TemplateLayerDefinition $template, ClassSet $classes): array
    {
        $tuples = self::collectObservedTuples($template, $classes);

        return self::sortTuplesLexicographically($tuples, $template->variables());
    }

    /**
     * @return list<array<string, string>>
     */
    private static function collectObservedTuples(TemplateLayerDefinition $template, ClassSet $classes): array
    {
        $membership = $template->membership();

        [$captureProducing, $nonCapturePatterns] = self::splitPatterns($membership->patterns);

        if ($captureProducing === []) {
            // TemplateLayerDefinition's invariant guarantees at least one
            // capture-producing pattern, but defend against future contract
            // drift.
            return [];
        }

        /** @var array<string, array<string, string>> */
        $observed = [];

        foreach ($classes->classes() as $classPath) {
            $context = $classes->contextFor($classPath);
            if ($context->fqn === '') {
                continue;
            }

            if (!self::passesNonCapturePatterns($nonCapturePatterns, $context)) {
                continue;
            }

            $tuple = self::extractTuple($captureProducing, $context->fqn, $membership->mode);
            if ($tuple === null) {
                continue;
            }

            if (!self::passesNonPatternCriteria($membership, $context)) {
                continue;
            }

            // M1 — apply the template's exclude clause AFTER capture binding
            // succeeds, using the substituted bindings. A class that would be
            // removed from the concrete layer at runtime must not contribute
            // a tuple, otherwise template expansion produces a "phantom"
            // concrete layer driven solely by classes that are then unassigned
            // (and the layer itself would be empty under runtime classification).
            if ($membership->exclude !== null && self::excludeFires($membership->exclude, $context, $tuple)) {
                continue;
            }

            $key = self::tupleKey($tuple);
            $observed[$key] ??= $tuple;
        }

        return array_values($observed);
    }

    /**
     * Splits patterns into capture-producing (compiled to {@see CapturePattern})
     * and non-capture (raw strings routed through {@see NamespaceMatcher} so
     * non-glob filters keep Phase-1 prefix semantics).
     *
     * **Why two engines?** {@see CapturePattern}'s regex anchors with `^...$`
     * and uses exact-character matching for any non-glob, non-capture residue
     * — perfect for substituted concrete patterns, wrong for filter patterns
     * like {@code App\Domain} which a Phase-1 user reasonably expects to match
     * {@code App\Domain\Foo} too. {@see NamespaceMatcher::matchesSingle()}
     * implements the documented Phase-1 prefix semantics. Routing non-capture
     * filters through it preserves the D7 carve-out's "filter behaves like a
     * Phase-1 pattern" intuition.
     *
     * @param list<string> $patterns
     *
     * @return array{0: list<CapturePattern>, 1: list<string>}
     */
    private static function splitPatterns(array $patterns): array
    {
        $capture = [];
        $nonCapture = [];
        foreach ($patterns as $pattern) {
            if (CapturePattern::isCaptureProducing($pattern)) {
                $capture[] = CapturePattern::compile($pattern);
            } else {
                $nonCapture[] = $pattern;
            }
        }

        return [$capture, $nonCapture];
    }

    /**
     * Returns true if the class FQN matches every non-capture pattern
     * (D7 AND-filter). Empty non-capture pattern list trivially passes.
     *
     * Delegates to {@see NamespaceMatcher::matchesSingle()} so non-glob filter
     * patterns ({@code App\Domain}) keep Phase-1 prefix semantics — they
     * match the namespace itself AND any class beneath it.
     *
     * @param list<string> $patterns
     */
    private static function passesNonCapturePatterns(array $patterns, ClassContext $context): bool
    {
        foreach ($patterns as $pattern) {
            if (!NamespaceMatcher::matchesSingle(rtrim($pattern, '\\'), $context->fqn)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns true if the class context satisfies the declared non-pattern
     * criteria (suffix / attributes / implements / extends) under the
     * membership's match mode.
     *
     * **M2 Path B (Phase 5.2).** Pre-remediation, this method enforced AND
     * across every declared non-pattern criterion regardless of the
     * membership's {@see MatchMode} — a deviation from the D2 runtime
     * semantics in
     * {@see \Qualimetrix\Architecture\Domain\Layer\LayerDefinition::matches()}.
     * The remediation aligns the two:
     *
     * - {@see MatchMode::Any}: a class that already binds via the
     *   capture-producing pattern passes here regardless of the non-pattern
     *   criteria. If the caller supplied any non-pattern criteria, they
     *   widen membership (OR) rather than narrow it — this matches the
     *   runtime D2 "any declared kind matches" semantics. A class that
     *   matches NEITHER the capture pattern (already filtered above) NOR
     *   any non-pattern criterion is implicitly absent from this code path.
     *
     * - {@see MatchMode::All}: every declared non-pattern criterion must
     *   match, mirroring the pre-remediation behavior. The capture pattern
     *   matching has already been verified by the caller.
     *
     * Empty (undeclared) criteria are trivially satisfied under either
     * mode.
     */
    private static function passesNonPatternCriteria(MembershipSpec $membership, ClassContext $context): bool
    {
        if ($membership->mode === MatchMode::Any) {
            // Capture pattern already matched in the caller — that alone
            // establishes membership under D2. Non-pattern criteria, when
            // declared, only widen the set, so a class that reaches this
            // point trivially passes.
            return true;
        }

        // MatchMode::All — every declared non-pattern criterion must match.
        if ($membership->suffix !== [] && !self::matchesAnySuffix($membership->suffix, $context->shortName)) {
            return false;
        }

        if ($membership->attributes !== [] && !self::haystackContainsAny($membership->attributes, $context->attributeFqns)) {
            return false;
        }

        if ($membership->implements !== [] && !self::haystackContainsAny($membership->implements, $context->interfaces)) {
            return false;
        }

        if ($membership->extends !== [] && !self::haystackContainsAny($membership->extends, $context->parentClasses)) {
            return false;
        }

        return true;
    }

    /**
     * Evaluates the template's exclude clause against the class using the
     * bindings produced by the matched capture-producing pattern.
     *
     * Exclude patterns may reference the same capture variables as the
     * template name; we substitute the bindings first, then evaluate the
     * concrete exclude criteria with the same primitive (
     * {@see LayerCriteriaMatcher::collectMatches()}) used by runtime
     * membership in {@see \Qualimetrix\Architecture\Domain\Layer\LayerDefinition}.
     * Non-pattern criteria on exclude do not currently support captures and
     * pass through verbatim.
     *
     * The exclude clause's own {@see ExcludeSpec::$mode} governs combination:
     * {@see MatchMode::Any} fires as soon as any declared kind matches;
     * {@see MatchMode::All} requires every declared kind to match.
     *
     * @param array<string, string> $bindings
     */
    private static function excludeFires(ExcludeSpec $exclude, ClassContext $context, array $bindings): bool
    {
        $substitutedPatterns = array_map(
            static fn(string $pattern): string => CapturePattern::applySubstitution($pattern, $bindings),
            $exclude->patterns,
        );
        $normalizedPatterns = LayerCriteriaMatcher::normalizePatterns($substitutedPatterns);

        $matched = LayerCriteriaMatcher::collectMatches(
            $context,
            $normalizedPatterns,
            $substitutedPatterns,
            $exclude->suffix,
            $exclude->attributes,
            $exclude->implements,
            $exclude->extends,
        );

        if ($matched === []) {
            return false;
        }

        if ($exclude->mode === MatchMode::Any) {
            return true;
        }

        $declaredKinds = LayerCriteriaMatcher::declaredKindCount(
            $exclude->patterns,
            $exclude->suffix,
            $exclude->attributes,
            $exclude->implements,
            $exclude->extends,
        );

        return \count($matched) === $declaredKinds;
    }

    /**
     * @param list<string> $suffixes
     */
    private static function matchesAnySuffix(array $suffixes, string $shortName): bool
    {
        if ($shortName === '') {
            return false;
        }

        foreach ($suffixes as $suffix) {
            if (str_ends_with($shortName, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $needles
     * @param list<string> $haystack
     */
    private static function haystackContainsAny(array $needles, array $haystack): bool
    {
        if ($haystack === []) {
            return false;
        }

        $set = array_fill_keys($haystack, true);
        foreach ($needles as $needle) {
            if (isset($set[$needle])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extracts a binding tuple from the capture-producing patterns, combined
     * per the template's match mode:
     *
     * - {@see MatchMode::Any}: first matching pattern wins; the tuple
     *   contains its captures. Variables not bound by that single match
     *   pass through unbound — they would only be relevant if a later
     *   pattern matches, but {@code any} short-circuits on first hit.
     *
     * - {@see MatchMode::All}: every capture-producing pattern must match,
     *   and the union of bindings must be consistent (same variable mapped
     *   to the same value across patterns).
     *
     * Returns null when no tuple can be produced.
     *
     * @param list<CapturePattern> $patterns
     *
     * @return array<string, string>|null
     */
    private static function extractTuple(array $patterns, string $fqn, MatchMode $mode): ?array
    {
        if ($mode === MatchMode::Any) {
            foreach ($patterns as $pattern) {
                $bindings = $pattern->match($fqn);
                if ($bindings !== null) {
                    return $bindings;
                }
            }

            return null;
        }

        // MatchMode::All
        $union = [];
        foreach ($patterns as $pattern) {
            $bindings = $pattern->match($fqn);
            if ($bindings === null) {
                return null;
            }

            foreach ($bindings as $name => $value) {
                if (isset($union[$name]) && $union[$name] !== $value) {
                    // Conflicting bindings — pattern set is inconsistent for this FQN.
                    return null;
                }
                $union[$name] = $value;
            }
        }

        return $union;
    }

    /**
     * Builds a deterministic string key for tuple deduplication. The
     * delimiter is unlikely to appear in any sane binding value (PHP FQN
     * segments) but is fine even if it does — the only requirement is that
     * (value list, variable order) is uniquely encoded.
     *
     * @param array<string, string> $tuple
     */
    private static function tupleKey(array $tuple): string
    {
        ksort($tuple);

        return implode("\x1F", array_map(
            static fn(string $name, string $value): string => $name . "\x00" . $value,
            array_keys($tuple),
            array_values($tuple),
        ));
    }

    /**
     * Sorts the tuple list lexicographically by the template's variable
     * order. {@see TemplateLayerDefinition::$variables} is already sorted
     * alphabetically at construction, so callers see a deterministic
     * order regardless of declaration form.
     *
     * @param list<array<string, string>> $tuples
     * @param list<string> $variableOrder
     *
     * @return list<array<string, string>>
     */
    private static function sortTuplesLexicographically(array $tuples, array $variableOrder): array
    {
        usort($tuples, static function (array $a, array $b) use ($variableOrder): int {
            foreach ($variableOrder as $variable) {
                $cmp = strcmp($a[$variable] ?? '', $b[$variable] ?? '');
                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            return 0;
        });

        return $tuples;
    }
}
