<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion;

use Qualimetrix\Analysis\Policy\Architecture\Layer\CapturePattern;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContext;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassSet;
use Qualimetrix\Analysis\Policy\Architecture\Layer\CriterionOutcome;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ExcludeSpec;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerCriteriaMatcher;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchMode;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec;
use Qualimetrix\Analysis\Policy\Architecture\Layer\TemplateLayerDefinition;

/**
 * Walks a {@see ClassSet} once and collects the distinct observed binding
 * tuples for a {@see TemplateLayerDefinition}.
 *
 * Its public output is a deduplicated, lexicographically sorted list of
 * binding tuples. Two semantic constraints keep observation aligned with
 * runtime matching:
 *
 * - **Exclude during observation.** The template's {@see ExcludeSpec}
 *   is evaluated AFTER capture binding succeeds, using the substituted
 *   bindings — a class that would be removed from the concrete layer
 *   at runtime is also removed from tuple observation, so excluded classes
 *   do not contribute "phantom" concrete layers.
 *
 * - **Mode-aware non-pattern criteria.** Non-pattern criteria
 *   ({@code suffix}, {@code attributes}, {@code implements}, {@code extends})
 *   respect the membership's {@see MatchMode}, and answer it through the same
 *   {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\LayerCriteriaMatcher}
 *   runtime membership uses rather than through a copy kept here. Under
 *   {@see MatchMode::Any} a template may not declare one at all — the
 *   configuration refuses it, because only patterns carry the capture
 *   variables that would bind it to an instance — so what is left is the
 *   {@see MatchMode::All} reading, where every declared kind must match.
 *
 * **Capture-producing vs non-capturing patterns.** Within
 * {@see MembershipSpec::$patterns}, patterns are classified: a pattern that
 * contains at least one `{var}` placeholder is capture-producing; a plain
 * glob is non-capturing. A non-capturing pattern acts as an AND-filter here,
 * regardless of mode — it describes "where the layer lives". Note that
 * runtime matching does NOT read it that way: on the expanded layer both
 * spellings sit in the single {@code patterns} kind, whose entries are OR-ed,
 * so observation is the narrower of the two in this one shape.
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

            if ($checkNonPatternCriteria && !self::admitsNonPatternCriteria($membership, $context)) {
                continue;
            }

            // Apply the template's exclude clause AFTER capture binding
            // succeeds, using the substituted bindings. A class that would be
            // removed from the concrete layer at runtime must not contribute
            // a tuple, otherwise template expansion produces a "phantom"
            // concrete layer driven solely by classes that are then unassigned
            // (and the layer itself would be empty under runtime classification).
            if ($exclude !== null && self::excludeRemoves($exclude, $context, $tuple)) {
                continue;
            }

            $key = self::tupleKey($tuple);
            $observed[$key] ??= $tuple;
        }

        return array_values($observed);
    }

    /**
     * Splits patterns into capture-producing (compiled to {@see CapturePattern})
     * and non-capture raw strings. Both variants use the same Architecture DSL;
     * a bare FQN is a boundary-aware subtree in static and template-expanded
     * membership alike.
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
     * (they are an AND-filter). Empty non-capture pattern list trivially passes.
     *
     * @param list<string> $patterns
     */
    private static function passesNonCapturePatterns(array $patterns, ClassContext $context): bool
    {
        foreach ($patterns as $pattern) {
            if (!CapturePattern::matches($pattern, $context->fqn)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the non-pattern criteria admit the class, decided by the same
     * primitive runtime membership uses.
     *
     * Patterns are passed empty on purpose: the capture-producing pattern has
     * already been matched by the caller, and the template's raw {@code {var}}
     * spelling would not match a concrete FQN anyway. What is left is exactly
     * the {@see MatchMode::All} question over the four non-pattern kinds, and
     * {@see LayerCriteriaMatcher} answers it — a second implementation of this
     * predicate lived here once, and it is how observation and matching came to
     * disagree with each other while each looked right on its own.
     *
     * **A kind the run cannot decide admits the class.** Refusing it here would
     * delete the concrete layer, so runtime would never evaluate any class
     * against it and the doubt would come out as a plain non-match with nothing
     * to report. Observing the tuple keeps the layer alive and lets the
     * undecidable membership travel to `architecture.coverage-gap`, where a
     * reader sees it. `architecture.unreachable-layer` may name that layer
     * alongside — noisy rather than silent, which is the failure direction this
     * slice chooses.
     */
    private static function admitsNonPatternCriteria(MembershipSpec $membership, ClassContext $context): bool
    {
        $evaluation = LayerCriteriaMatcher::evaluate(
            $context,
            [],
            $membership->suffix,
            $membership->attributes,
            $membership->implements,
            $membership->extends,
        );

        $outcome = $evaluation->outcome(MatchMode::All, LayerCriteriaMatcher::declaredKindCount(
            [],
            $membership->suffix,
            $membership->attributes,
            $membership->implements,
            $membership->extends,
        ));

        return $outcome !== CriterionOutcome::DoesNotMatch;
    }

    /**
     * Whether the template's exclude clause removes the class, evaluated with
     * the bindings produced by the matched capture-producing pattern.
     *
     * Exclude patterns may reference the same capture variables as the template
     * name; substitution happens here and the concrete criteria then go through
     * {@see LayerDefinition::excludeOutcome()}, the same entry point runtime
     * membership uses, mode combination included. Non-pattern criteria on
     * exclude do not support captures and pass through verbatim.
     *
     * An exclude clause the run cannot decide does NOT remove the tuple, for
     * the reason {@see admitsNonPatternCriteria()} gives.
     *
     * @param array<string, string> $bindings
     */
    private static function excludeRemoves(ExcludeSpec $exclude, ClassContext $context, array $bindings): bool
    {
        $substitutedPatterns = array_map(
            static fn(string $pattern): string => CapturePattern::applySubstitution($pattern, $bindings),
            $exclude->patterns,
        );

        return LayerDefinition::excludeOutcome($context, $exclude, $substitutedPatterns) === CriterionOutcome::Matches;
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
