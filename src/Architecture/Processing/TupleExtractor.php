<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Processing;

use Qualimetrix\Architecture\Domain\Layer\CapturePattern;
use Qualimetrix\Architecture\Domain\Layer\ClassContext;
use Qualimetrix\Architecture\Domain\Layer\ClassSet;
use Qualimetrix\Architecture\Domain\Layer\MatchMode;
use Qualimetrix\Architecture\Domain\Layer\MembershipSpec;
use Qualimetrix\Architecture\Domain\Layer\TemplateLayerDefinition;
use Qualimetrix\Core\Util\NamespaceMatcher;

/**
 * Walks a {@see ClassSet} once and collects the distinct observed binding
 * tuples for a {@see TemplateLayerDefinition}.
 *
 * Extracted from {@see LayerExpansionStage} in Phase 4.1 of the remediation
 * (ADR 0008). Behavior-preserving — the algorithm and its public output
 * (deduplicated list of binding tuples, lex-sorted) are unchanged.
 *
 * **Capture-producing vs non-capturing criteria (D7 carve-out).** Within
 * {@see MembershipSpec::$patterns}, patterns are classified: a pattern that
 * contains at least one `{var}` placeholder is capture-producing; a plain
 * glob is non-capturing. {@see MembershipSpec::$mode} (`match: any|all`)
 * governs only the combination of capture-producing patterns. Non-capturing
 * patterns plus the {@code suffix}, {@code attributes}, {@code implements},
 * and {@code extends} criteria ALWAYS act as an AND-filter.
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

            if (!self::passesNonPatternCriteria($membership, $context)) {
                continue;
            }

            $tuple = self::extractTuple($captureProducing, $context->fqn, $membership->mode);
            if ($tuple === null) {
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
     * criterion (suffix / attributes / implements / extends). Empty criteria
     * trivially pass — D7 always AND.
     */
    private static function passesNonPatternCriteria(MembershipSpec $membership, ClassContext $context): bool
    {
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
