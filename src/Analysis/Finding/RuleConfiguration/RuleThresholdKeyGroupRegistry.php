<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration;

/**
 * Explicit, hand-maintained catalog of `threshold` vs. `warning`/`error` key
 * groups for every rule that uses {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser}.
 *
 * {@see RuleOptionThresholdModeResolver} consults this registry FIRST when
 * deciding which keys belong together for a given rule — and, for
 * hierarchical rules, a given nesting path (`method`, `class`, `namespace`,
 * or `''` for the rule's own top level). Each entry mirrors the literal
 * `$warningKey`/`$errorKey`/`$thresholdKey`/`$legacyKeys` arguments already
 * passed to `ThresholdParser::parse()` at that rule's `Options::fromArray()`
 * call site — it does not invent new information, it just makes explicit,
 * in one place, a pairing that already exists at each call site.
 *
 * ## Why this isn't derived directly from the Options class
 *
 * `RuleOptionThresholdModeResolver` runs during configuration *merging*
 * (preset -> config file, config file -> CLI), which happens before any
 * rule's `Options::fromArray()` is ever invoked — it cannot ask
 * `ThresholdParser::parse()` "which keys did you use" at the point it needs
 * the answer, since that call hasn't happened yet. Worse: Options classes
 * live with their owning rule capability under exact
 * `src/Analysis/Evidence/{Capability}/` or `src/Analysis/Policy/{Capability}/`
 * roots, and `Configuration` may not depend on those owners. So the Options
 * class cannot simply implement an interface the resolver calls at merge
 * time either, not without reversing the module dependency direction.
 *
 * This registry is the closest available equivalent to "the class declares
 * its own keys" that stays inside the `Configuration` layer: it lives next
 * to the resolver that consumes it, and each entry mirrors — rather than
 * reinterprets — the corresponding `ThresholdParser::parse()` call. Every
 * entry is exercised end-to-end (through the real Options class) by the
 * regression tests in `RuleOptionsFactoryTest` / `ConfigurationMergerTest`,
 * so a call-site change that silently drifts out of sync with its registry
 * entry fails a test rather than misbehaving silently in production.
 *
 * ## Maintenance
 *
 * Adding or changing a rule's graduated/threshold key spelling? Update (or
 * add) its entry in {@see GROUPS}, matching the corresponding
 * `ThresholdParser::parse()` call's arguments. Reuse one of the shared
 * key-group constants below ({@see BARE_PAIR}, {@see MAX_PREFIXED_PAIR},
 * {@see LEGACY_FLAT_ALIAS_PAIR}) when a rule's spelling matches one exactly
 * — most rules do, since bare `warning`/`error` and `max_`-prefixed
 * `warning`/`error` are by far the two most common spellings across the
 * codebase. Only write out a fresh literal group when the spelling is
 * actually unique to that rule (e.g. `max_distance_warning`, `vo_warning`,
 * `param_warning`). A rule with NO entry here falls back to
 * {@see RuleOptionThresholdModeResolver}'s suffix/prefix heuristic, which is
 * unreliable for non-bare key spellings (see that method's docblock) — every
 * rule known to this codebase at the time of writing has an entry, so the
 * heuristic is not actually exercised for any of them; it exists only as a
 * safety net for a future rule added without a matching registry entry.
 *
 * Key spellings only need ONE canonical form per entry — matching is
 * case/separator-insensitive (`max_distance_warning` and
 * `maxDistanceWarning` both match), so camelCase/snake_case/kebab-case
 * variants of the *same word* don't need separate entries. A DIFFERENT word
 * that aliases the same concept (e.g. `warningThreshold` as a legacy name
 * for `warning`, on `complexity.cyclomatic`'s top-level legacy-flat mode)
 * DOES need its own entry in the corresponding list, precisely because a
 * plain suffix heuristic would otherwise misclassify it as a `threshold`
 * marker (it ends in "Threshold") instead of a `warning` alias.
 *
 * @phpstan-type ThresholdKeyGroupShape array{warning: list<string>, error: list<string>, threshold: list<string>}
 */
final class RuleThresholdKeyGroupRegistry
{
    /**
     * @return list<ThresholdKeyGroupShape>
     */
    public static function groupsFor(string $ruleName, string $path): array
    {
        return self::GROUPS[$ruleName][$path] ?? [];
    }

    /**
     * Bare `warning`/`error`/`threshold` — the single most common spelling in
     * the codebase. Used verbatim (no prefix, no rule-specific word) by every
     * rule below that references it.
     *
     * @var ThresholdKeyGroupShape
     */
    private const array BARE_PAIR = ['warning' => ['warning'], 'error' => ['error'], 'threshold' => ['threshold']];

    /**
     * `max_warning`/`max_error`/`threshold` — the second most common
     * spelling, used for metrics where lower is better (instability,
     * cognitive/cyclomatic/npath complexity's class level: "no MORE than
     * this many").
     *
     * @var ThresholdKeyGroupShape
     */
    private const array MAX_PREFIXED_PAIR = ['warning' => ['max_warning'], 'error' => ['max_error'], 'threshold' => ['threshold']];

    /**
     * `warningThreshold`/`errorThreshold`/`threshold` — legacy top-level
     * ALIASES for warning/error on `complexity.cyclomatic`/`cognitive`/
     * `npath`'s method dimension, not a `max_`-style rename. Kept as its own
     * constant (rather than folded into {@see BARE_PAIR}) precisely because
     * a plain suffix heuristic would otherwise misclassify `warningThreshold`
     * as a `threshold` marker (it ends in "Threshold") instead of a
     * `warning` alias — see this class's own docblock.
     *
     * @var ThresholdKeyGroupShape
     */
    private const array LEGACY_FLAT_ALIAS_PAIR = ['warning' => ['warningThreshold'], 'error' => ['errorThreshold'], 'threshold' => ['threshold']];

    /**
     * @var array<string, array<string, list<ThresholdKeyGroupShape>>>
     */
    private const array GROUPS = [
        // design.type-coverage — 3 independent, prefix-consistent dimensions
        // (TypeCoverageOptions::fromArray()). Each prefix is unique to this
        // rule, so no shared constant applies.
        'design.type-coverage' => [
            '' => [
                ['warning' => ['param_warning'], 'error' => ['param_error'], 'threshold' => ['param_threshold']],
                ['warning' => ['return_warning'], 'error' => ['return_error'], 'threshold' => ['return_threshold']],
                ['warning' => ['property_warning'], 'error' => ['property_error'], 'threshold' => ['property_threshold']],
            ],
        ],

        // complexity.cyclomatic / complexity.cognitive / complexity.npath
        // (hierarchical callable/class options with an identical shape):
        // top-level legacy-flat shorthand applies only to the callable
        // dimension — the bare `warning`/`error` keys are NOT part of that
        // top-level group: the legacy-flat branch's own trigger condition
        // (`ComplexityOptions::fromArray()` et al.) only checks for
        // `warningThreshold`/`errorThreshold`/`threshold` — bare
        // `warning`/`error` at the rule's top level are never inspected by
        // it at all and fall through as unknown options.
        'complexity.cyclomatic' => [
            '' => [self::LEGACY_FLAT_ALIAS_PAIR],
            'callable' => [self::BARE_PAIR],
            'class' => [self::MAX_PREFIXED_PAIR],
        ],
        'complexity.cognitive' => [
            '' => [self::LEGACY_FLAT_ALIAS_PAIR],
            'callable' => [self::BARE_PAIR],
            'class' => [self::MAX_PREFIXED_PAIR],
        ],
        'complexity.npath' => [
            '' => [self::LEGACY_FLAT_ALIAS_PAIR],
            'callable' => [self::BARE_PAIR],
            'class' => [self::MAX_PREFIXED_PAIR],
        ],

        // coupling.cbo (CboOptions: hierarchical class/namespace, bare keys).
        // The '' (top-level) entry is the rule's own flat-shorthand branch —
        // a bare threshold/warning/error applied uniformly to BOTH the class
        // and namespace dimensions instead of the nested sub-configs (see
        // CboOptions::fromArray()'s docblock for why, unlike
        // complexity.cyclomatic/cognitive/npath's top-level legacy-flat
        // branch, this one does NOT disable a level).
        'coupling.cbo' => [
            '' => [self::BARE_PAIR],
            'class' => [self::BARE_PAIR],
            'namespace' => [self::BARE_PAIR],
        ],

        // coupling.instability (InstabilityOptions: hierarchical
        // class/namespace, max_* graduated keys). The '' entry mirrors
        // coupling.cbo's own top-level flat-shorthand branch, applied
        // uniformly to both levels.
        'coupling.instability' => [
            '' => [self::MAX_PREFIXED_PAIR],
            'class' => [self::MAX_PREFIXED_PAIR],
            'namespace' => [self::MAX_PREFIXED_PAIR],
        ],

        // coupling.distance (DistanceOptions) — flat, max_distance_* graduated
        // keys paired with a bare `threshold` shorthand. Prefix is unique to
        // this rule, so no shared constant applies.
        'coupling.distance' => [
            '' => [
                ['warning' => ['max_distance_warning'], 'error' => ['max_distance_error'], 'threshold' => ['threshold']],
            ],
        ],

        // coupling.class-rank (ClassRankOptions) — flat, bare keys.
        'coupling.class-rank' => [
            '' => [self::BARE_PAIR],
        ],

        // code-smell.long-parameter-list (LongParameterListOptions) — flat,
        // TWO independent dimensions at the same level: the bare pair and
        // the vo-prefixed pair (readonly VO constructor thresholds, unique
        // to this rule).
        'code-smell.long-parameter-list' => [
            '' => [
                self::BARE_PAIR,
                ['warning' => ['vo_warning'], 'error' => ['vo_error'], 'threshold' => ['vo_threshold']],
            ],
        ],

        // code-smell.constructor-overinjection (ConstructorOverinjectionOptions) — flat, bare.
        'code-smell.constructor-overinjection' => [
            '' => [self::BARE_PAIR],
        ],

        // code-smell.unreachable-code (UnreachableCodeOptions) — flat, bare.
        'code-smell.unreachable-code' => [
            '' => [self::BARE_PAIR],
        ],

        // maintainability.index (MaintainabilityOptions) — flat, bare.
        'maintainability.index' => [
            '' => [self::BARE_PAIR],
        ],

        // size.method-count / size.class-count / size.property-count — flat, bare.
        'size.method-count' => [
            '' => [self::BARE_PAIR],
        ],
        'size.class-count' => [
            '' => [self::BARE_PAIR],
        ],
        'size.property-count' => [
            '' => [self::BARE_PAIR],
        ],

        // design.inheritance / design.noc / design.lcom / complexity.wmc — flat, bare.
        'design.inheritance' => [
            '' => [self::BARE_PAIR],
        ],
        'design.noc' => [
            '' => [self::BARE_PAIR],
        ],
        'design.lcom' => [
            '' => [self::BARE_PAIR],
        ],
        'complexity.wmc' => [
            '' => [self::BARE_PAIR],
        ],

        // duplication.code-duplication (CodeDuplicationOptions) — flat, bare.
        'duplication.code-duplication' => [
            '' => [self::BARE_PAIR],
        ],
    ];
}
