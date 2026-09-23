<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionValueForm;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Explicit, hand-maintained catalog of `threshold` vs. `warning`/`error` key
 * groups for every rule that uses {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser}.
 *
 * {@see RuleOptionThresholdShorthand} consults this registry to decide which
 * keys belong together for a given rule — and, for hierarchical rules, a
 * given nesting path (`callable`, `class`, `namespace`, or `''` for the
 * rule's own top level) — before unfolding a `threshold` shorthand into that
 * pair. A rule/path with NO entry is left entirely untouched: there is no
 * guessing fallback any more (see below). Each entry mirrors the literal
 * `$warningKey`/`$errorKey`/`$thresholdKey`/`$legacyKeys` arguments already
 * passed to `ThresholdParser::parse()` at that rule's `Options::fromArray()`
 * call site — it does not invent new information, it just makes explicit,
 * in one place, a pairing that already exists at each call site. The one
 * exception is a key a call site names but the option-key refusal rejects at
 * depth 1 before any merge happens: unfolding can never see it, so the entry
 * omits it — see {@see LONE_THRESHOLD}.
 *
 * ## Why this isn't derived directly from the Options class
 *
 * `RuleOptionThresholdShorthand` runs during configuration *merging*
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
 * to the class that consumes it, and each entry mirrors — rather than
 * reinterprets — the corresponding `ThresholdParser::parse()` call. Every
 * entry is exercised end-to-end (through the real Options class) by the
 * regression tests in `RuleOptionsFactoryTest`,
 * and its completeness against every real call site is proved mechanically
 * by {@see \Qualimetrix\Governance\ThresholdKeys\RuleThresholdKeyGroupRegistryCompletenessTest},
 * so a call-site change that silently drifts out of sync with its registry
 * entry fails a test rather than misbehaving silently in production.
 *
 * ## Maintenance
 *
 * Adding or changing a rule's graduated/threshold key spelling? Update (or
 * add) its entry in {@see GROUPS}, matching the corresponding
 * `ThresholdParser::parse()` call's arguments. Reuse one of the shared
 * key-group constants below ({@see BARE_PAIR}, {@see MAX_PREFIXED_PAIR},
 * {@see LONE_THRESHOLD}) when a rule's spelling matches one exactly
 * — most rules do, since bare `warning`/`error` and `max_`-prefixed
 * `warning`/`error` are by far the two most common spellings across the
 * codebase. Only write out a fresh literal group when the spelling is
 * actually unique to that rule (e.g. `max_distance_warning`,
 * `vo_warning`). A rule with NO entry here is left entirely untouched by
 * unfolding — there is no guessing fallback: a cross-layer mode change for
 * such a rule (e.g. a preset's `warning`/`error` under a `qmx.yaml`
 * `threshold`) reaches `ThresholdParser::parse()` whole and is refused as a
 * mix. {@see \Qualimetrix\Governance\ThresholdKeys\RuleThresholdKeyGroupRegistryCompletenessTest}
 * proves every rule/path that actually calls `ThresholdParser::parse()` has
 * an entry here, so that refusal is never reached for a rule this codebase
 * ships today.
 *
 * Key spellings only need ONE canonical form per entry — matching is
 * case/separator-insensitive (`max_distance_warning` and
 * `maxDistanceWarning` both match), so camelCase/snake_case/kebab-case
 * variants of the *same word* don't need separate entries. A DIFFERENT word
 * that aliases the same concept would need its own entry in the corresponding
 * list. No rule declares such an alias today: an undeclared option key is
 * refused at the option-key seam rather than mirrored here.
 *
 * Each entry also carries the `form` its `threshold` key is declared with in
 * the real `acceptedOptionKeys()` — the SAME scalar form the graduated
 * `warning`/`error` keys are declared with, since a rule always uses one form
 * for a whole group. {@see RuleOptionThresholdShorthand} checks a written
 * `threshold` value against this form before unfolding: a value of the WRONG
 * form (a string, a list) is left under its own `threshold` key rather than
 * unfolded into `warning`/`error`, so a refusal always names the key the
 * author actually wrote.
 * {@see \Qualimetrix\Governance\ThresholdKeys\RuleThresholdKeyGroupRegistryCompletenessTest}
 * proves every declared `form` matches the real Options class's own
 * declaration for all three keys of the group.
 *
 * @phpstan-type ThresholdKeyGroupShape array{warning: list<string>, error: list<string>, threshold: list<string>, form: RuleOptionValueForm}
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
     * Bare `warning`/`error`/`threshold` key SPELLING — the single most
     * common spelling in the codebase. Used verbatim (no prefix, no
     * rule-specific word) by every rule below that references it. The
     * SPELLING is shared; the scalar `form` is not — a rule's own
     * `acceptedOptionKeys()` decides whether its bare pair is a whole number
     * or a fraction, so `form` is spread in at each usage site below rather
     * than baked into this constant.
     *
     * @var array{warning: list<string>, error: list<string>, threshold: list<string>}
     */
    private const array BARE_PAIR = ['warning' => ['warning'], 'error' => ['error'], 'threshold' => ['threshold']];

    /**
     * `max_warning`/`max_error`/`threshold` key SPELLING — the second most
     * common spelling, used for metrics where lower is better (instability,
     * cognitive/cyclomatic/npath complexity's class level: "no MORE than
     * this many"). See {@see BARE_PAIR} for why `form` is not baked in here.
     *
     * @var array{warning: list<string>, error: list<string>, threshold: list<string>}
     */
    private const array MAX_PREFIXED_PAIR = ['warning' => ['max_warning'], 'error' => ['max_error'], 'threshold' => ['threshold']];

    /**
     * A `threshold` shorthand with no graduated pair beside it — the shape of
     * a top level that takes the shorthand and nothing else. The empty
     * `warning`/`error` lists are load-bearing: unfolding has nothing to
     * write for a spelling that is refused at depth 1 before any merge
     * happens.
     *
     * @var array{warning: list<string>, error: list<string>, threshold: list<string>}
     */
    private const array LONE_THRESHOLD_SHAPE = ['warning' => [], 'error' => [], 'threshold' => ['threshold']];

    /**
     * @var array<string, array<string, list<ThresholdKeyGroupShape>>>
     */
    private const array GROUPS = [
        // The three type-coverage dimensions — one rule each, flat and bare
        // (TypeCoverageOptions::fromArray(), shared by all three). The prefix
        // that used to distinguish them lives in the rule name now. Declared
        // `number()` (a percentage, so fractional).
        'design.type-coverage.param' => [
            '' => [[...self::BARE_PAIR, 'form' => RuleOptionValueForm::Number]],
        ],
        'design.type-coverage.return' => [
            '' => [[...self::BARE_PAIR, 'form' => RuleOptionValueForm::Number]],
        ],
        'design.type-coverage.property' => [
            '' => [[...self::BARE_PAIR, 'form' => RuleOptionValueForm::Number]],
        ],

        // complexity.ccn / complexity.cognitive / complexity.npath
        // (hierarchical callable/class options with an identical shape):
        // the top-level shorthand applies only to the callable dimension, and
        // `threshold` is the only key that opens it — the branch's trigger
        // condition (`ComplexityOptions::fromArray()` et al.) checks for
        // nothing else. Bare `warning`/`error` at the rule's top level are
        // never inspected by it at all and are refused as unknown options.
        // All three keys, at every path, declare `integer()`.
        'complexity.ccn' => [
            '' => [[...self::LONE_THRESHOLD_SHAPE, 'form' => RuleOptionValueForm::WholeNumber]],
            SymbolLevel::Callable->value => [[...self::BARE_PAIR, 'form' => RuleOptionValueForm::WholeNumber]],
            SymbolLevel::Class_->value => [[...self::MAX_PREFIXED_PAIR, 'form' => RuleOptionValueForm::WholeNumber]],
        ],
        'complexity.cognitive' => [
            '' => [[...self::LONE_THRESHOLD_SHAPE, 'form' => RuleOptionValueForm::WholeNumber]],
            SymbolLevel::Callable->value => [[...self::BARE_PAIR, 'form' => RuleOptionValueForm::WholeNumber]],
            SymbolLevel::Class_->value => [[...self::MAX_PREFIXED_PAIR, 'form' => RuleOptionValueForm::WholeNumber]],
        ],
        'complexity.npath' => [
            '' => [[...self::LONE_THRESHOLD_SHAPE, 'form' => RuleOptionValueForm::WholeNumber]],
            SymbolLevel::Callable->value => [[...self::BARE_PAIR, 'form' => RuleOptionValueForm::WholeNumber]],
            SymbolLevel::Class_->value => [[...self::MAX_PREFIXED_PAIR, 'form' => RuleOptionValueForm::WholeNumber]],
        ],

        // coupling.cbo (CboOptions: hierarchical class/namespace, bare keys,
        // all declared `integer()`).
        // The '' (top-level) entry is the rule's own flat-shorthand branch —
        // a bare threshold/warning/error applied uniformly to BOTH the class
        // and namespace dimensions instead of the nested sub-configs (see
        // CboOptions::fromArray()'s docblock for why, unlike
        // complexity.ccn/cognitive/npath's top-level legacy-flat
        // branch, this one does NOT disable a level).
        'coupling.cbo' => [
            '' => [[...self::BARE_PAIR, 'form' => RuleOptionValueForm::WholeNumber]],
            SymbolLevel::Class_->value => [[...self::BARE_PAIR, 'form' => RuleOptionValueForm::WholeNumber]],
            SymbolLevel::Namespace_->value => [[...self::BARE_PAIR, 'form' => RuleOptionValueForm::WholeNumber]],
        ],

        // coupling.instability (InstabilityOptions: hierarchical
        // class/namespace, max_* graduated keys, all declared `number()` —
        // instability is a 0..1 ratio). The '' entry mirrors coupling.cbo's
        // own top-level flat-shorthand branch, applied uniformly to both
        // levels.
        'coupling.instability' => [
            '' => [[...self::MAX_PREFIXED_PAIR, 'form' => RuleOptionValueForm::Number]],
            SymbolLevel::Class_->value => [[...self::MAX_PREFIXED_PAIR, 'form' => RuleOptionValueForm::Number]],
            SymbolLevel::Namespace_->value => [[...self::MAX_PREFIXED_PAIR, 'form' => RuleOptionValueForm::Number]],
        ],

        // coupling.distance (DistanceOptions) — flat, max_distance_* graduated
        // keys paired with a bare `threshold` shorthand, all declared
        // `number()`. Prefix is unique to this rule, so no shared constant
        // applies.
        'coupling.distance' => [
            '' => [
                [
                    'warning' => ['max_distance_warning'],
                    'error' => ['max_distance_error'],
                    'threshold' => ['threshold'],
                    'form' => RuleOptionValueForm::Number,
                ],
            ],
        ],

        // coupling.class-rank (ClassRankOptions) — flat, bare keys, `number()`.
        'coupling.class-rank' => [
            '' => [[...self::BARE_PAIR, 'form' => RuleOptionValueForm::Number]],
        ],

        // code-smell.long-parameter-list (LongParameterListOptions) — flat,
        // TWO independent dimensions at the same level: the bare pair and
        // the vo-prefixed pair (readonly VO constructor thresholds, unique
        // to this rule). Both declared `integer()`.
        'code-smell.long-parameter-list' => [
            '' => [
                [...self::BARE_PAIR, 'form' => RuleOptionValueForm::WholeNumber],
                [
                    'warning' => ['vo_warning'],
                    'error' => ['vo_error'],
                    'threshold' => ['vo_threshold'],
                    'form' => RuleOptionValueForm::WholeNumber,
                ],
            ],
        ],

        // code-smell.constructor-overinjection (ConstructorOverinjectionOptions) — flat, bare, `integer()`.
        'code-smell.constructor-overinjection' => [
            '' => [[...self::BARE_PAIR, 'form' => RuleOptionValueForm::WholeNumber]],
        ],

        // code-smell.unreachable-code (UnreachableCodeOptions) — flat, bare, `integer()`.
        'code-smell.unreachable-code' => [
            '' => [[...self::BARE_PAIR, 'form' => RuleOptionValueForm::WholeNumber]],
        ],

        // maintainability.mi (MaintainabilityOptions) — flat, bare, `number()`.
        'maintainability.mi' => [
            '' => [[...self::BARE_PAIR, 'form' => RuleOptionValueForm::Number]],
        ],

        // size.method-count / size.class-count / size.property-count — flat, bare, `integer()`.
        'size.method-count' => [
            '' => [[...self::BARE_PAIR, 'form' => RuleOptionValueForm::WholeNumber]],
        ],
        'size.class-count' => [
            '' => [[...self::BARE_PAIR, 'form' => RuleOptionValueForm::WholeNumber]],
        ],
        'size.property-count' => [
            '' => [[...self::BARE_PAIR, 'form' => RuleOptionValueForm::WholeNumber]],
        ],

        // design.dit / design.noc / cohesion.lcom / complexity.wmc — flat, bare, `integer()`.
        'design.dit' => [
            '' => [[...self::BARE_PAIR, 'form' => RuleOptionValueForm::WholeNumber]],
        ],
        'design.noc' => [
            '' => [[...self::BARE_PAIR, 'form' => RuleOptionValueForm::WholeNumber]],
        ],
        'cohesion.lcom' => [
            '' => [[...self::BARE_PAIR, 'form' => RuleOptionValueForm::WholeNumber]],
        ],
        'complexity.wmc' => [
            '' => [[...self::BARE_PAIR, 'form' => RuleOptionValueForm::WholeNumber]],
        ],

        // duplication.clone (CodeDuplicationOptions) — flat, bare, `integer()`.
        'duplication.clone' => [
            '' => [[...self::BARE_PAIR, 'form' => RuleOptionValueForm::WholeNumber]],
        ],
    ];
}
