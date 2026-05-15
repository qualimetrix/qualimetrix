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

        // Hoist the mode check out of the per-class loop: under MatchMode::Any
        // the post-pattern criteria check is a no-op (a class that bound via
        // the capture pattern is admitted regardless of non-pattern criteria),
        // so we skip the function call entirely.
        $checkNonPatternCriteria = $membership->mode === MatchMode::All;
        $exclude = $membership->exclude;

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

            if ($checkNonPatternCriteria && !self::matchAllNonPatternCriteria($membership, $context)) {
                continue;
            }

            // M1 — apply the template's exclude clause AFTER capture binding
            // succeeds, using the substituted bindings. A class that would be
            // removed from the concrete layer at runtime must not contribute
            // a tuple, otherwise template expansion produces a "phantom"
            // concrete layer driven solely by classes that are then unassigned
            // (and the layer itself would be empty under runtime classification).
            if ($exclude !== null && self::excludeFires($exclude, $context, $tuple)) {
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
     * Returns true if the class context satisfies every declared non-pattern
     * criterion (suffix / attributes / implements / extends).
     *
     * **M2 Path B (Phase 5.2).** Pre-remediation, this method enforced AND
     * across every declared non-pattern criterion regardless of the
     * membership's {@see MatchMode} — a deviation from the D2 runtime
     * semantics in
     * {@see \Qualimetrix\Architecture\Domain\Layer\LayerDefinition::matches()}.
     * The remediation aligns the two:
     *
     * - {@see MatchMode::Any}: a class that already binds via the
     *   capture-producing pattern passes regardless of the non-pattern
     *   criteria. Callers hoist this short-circuit out of the per-class
     *   loop and skip the helper entirely under Any.
     *
     * - {@see MatchMode::All}: every declared non-pattern criterion must
     *   match (this method's contract). The capture pattern matching has
     *   already been verified by the caller.
     *
     * Empty (undeclared) criteria are trivially satisfied — the early
     * `$x !== []` guards make sure we only run the haystack check when the
     * user actually declared a criterion of that kind.
     */
    private static function matchAllNonPatternCriteria(MembershipSpec $membership, ClassContext $context): bool
    {
        if ($membership->suffix !== [] && !self::matchesAnySuffix($membership->suffix, $context->shortName)) {
            return false;
        }

        if ($membership->attributes !== [] && !self::needleHits($membership->attributes, $context->attributeFqnSet)) {
            return false;
        }

        if ($membership->implements !== [] && !self::needleHits($membership->implements, $context->interfaceSet)) {
            return false;
        }

        if ($membership->extends !== [] && !self::needleHits($membership->extends, $context->parentClassSet)) {
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
     * Returns true if any of {@code $needles} is present as a key in the
     * already-prepared {@code $haystackSet}. Callers pass the precomputed
     * lookup tables on {@see ClassContext} (e.g. {@see ClassContext::$interfaceSet})
     * so the {@code array_fill_keys} cost is paid once per class — not once
     * per layer per class.
     *
     * @param list<string> $needles
     * @param array<string, true> $haystackSet
     */
    private static function needleHits(array $needles, array $haystackSet): bool
    {
        if ($haystackSet === []) {
            return false;
        }

        foreach ($needles as $needle) {
            if (isset($haystackSet[$needle])) {
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
     * delimiter pair ({@code 0x1F} between entries, {@code 0x00} between
     * name and value) is unlikely to appear in any sane binding value
     * (PHP FQN segments) but is fine even if it does — the only requirement
     * is that {@code (variable name, value)} pairs in canonical order
     * are uniquely encoded.
     *
     * Accepts a mutable copy on purpose: {@code ksort} mutates in place and
     * we want the local copy sorted without affecting the caller's tuple,
     * which is also stored in {@code $observed} for later dedup hits.
     *
     * @param array<string, string> $tuple
     */
    private static function tupleKey(array $tuple): string
    {
        ksort($tuple);

        $parts = [];
        foreach ($tuple as $name => $value) {
            $parts[] = $name . "\x00" . $value;
        }

        return implode("\x1F", $parts);
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
