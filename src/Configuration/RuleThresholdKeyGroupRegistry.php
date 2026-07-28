<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration;

/**
 * Explicit, hand-maintained catalog of `threshold` vs. `warning`/`error` key
 * groups for every rule that uses {@see \Qualimetrix\Rules\Support\ThresholdParser}.
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
 * the answer, since that call hasn't happened yet. Worse: `Options` classes
 * live in `src/Rules/{Category}/`, and `Configuration` may not depend on
 * `Rules` — see the `configuration: [core, architecture]` allow-list in the
 * project's own `qmx.yaml` (`rules` is deliberately absent), enforced by
 * `architecture.layer-violation`. So the Options class cannot simply
 * implement an interface the resolver calls at merge time either, not
 * without introducing a `Configuration -> Rules` edge.
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
 * add) its entry here, matching the corresponding `ThresholdParser::parse()`
 * call's arguments. A rule with NO entry here falls back to
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
     * @var array<string, array<string, list<ThresholdKeyGroupShape>>>
     */
    private const array GROUPS = [
        // design.type-coverage — 3 independent, prefix-consistent dimensions
        // (TypeCoverageOptions::fromArray()).
        'design.type-coverage' => [
            '' => [
                ['warning' => ['param_warning'], 'error' => ['param_error'], 'threshold' => ['param_threshold']],
                ['warning' => ['return_warning'], 'error' => ['return_error'], 'threshold' => ['return_threshold']],
                ['warning' => ['property_warning'], 'error' => ['property_error'], 'threshold' => ['property_threshold']],
            ],
        ],

        // complexity.cyclomatic (ComplexityOptions: hierarchical method/class).
        'complexity.cyclomatic' => [
            // Top-level legacy-flat shorthand — applies only to the method
            // dimension. `warningThreshold`/`errorThreshold` are legacy
            // ALIASES for warning/error, not threshold markers, despite the
            // "Threshold" suffix in their name. The bare `warning`/`error`
            // keys are NOT part of this group: the legacy-flat branch's own
            // trigger condition (`ComplexityOptions::fromArray()` et al.)
            // only checks for `warningThreshold`/`errorThreshold`/`threshold`
            // — bare `warning`/`error` at the rule's top level are never
            // inspected by it at all and fall through as unknown options.
            '' => [
                ['warning' => ['warningThreshold'], 'error' => ['errorThreshold'], 'threshold' => ['threshold']],
            ],
            'method' => [
                ['warning' => ['warning'], 'error' => ['error'], 'threshold' => ['threshold']],
            ],
            'class' => [
                ['warning' => ['max_warning'], 'error' => ['max_error'], 'threshold' => ['threshold']],
            ],
        ],

        // complexity.cognitive (CognitiveComplexityOptions: hierarchical method/class).
        'complexity.cognitive' => [
            // The bare `warning`/`error` keys are NOT part of this group: the
            // legacy-flat branch's own trigger condition
            // (`ComplexityOptions::fromArray()` et al.) only checks for
            // `warningThreshold`/`errorThreshold`/`threshold` — bare
            // `warning`/`error` at the rule's top level are never inspected
            // by it at all and fall through as unknown options.
            '' => [
                ['warning' => ['warningThreshold'], 'error' => ['errorThreshold'], 'threshold' => ['threshold']],
            ],
            'method' => [
                ['warning' => ['warning'], 'error' => ['error'], 'threshold' => ['threshold']],
            ],
            'class' => [
                ['warning' => ['max_warning'], 'error' => ['max_error'], 'threshold' => ['threshold']],
            ],
        ],

        // complexity.npath (NpathComplexityOptions: hierarchical method/class).
        'complexity.npath' => [
            // The bare `warning`/`error` keys are NOT part of this group: the
            // legacy-flat branch's own trigger condition
            // (`ComplexityOptions::fromArray()` et al.) only checks for
            // `warningThreshold`/`errorThreshold`/`threshold` — bare
            // `warning`/`error` at the rule's top level are never inspected
            // by it at all and fall through as unknown options.
            '' => [
                ['warning' => ['warningThreshold'], 'error' => ['errorThreshold'], 'threshold' => ['threshold']],
            ],
            'method' => [
                ['warning' => ['warning'], 'error' => ['error'], 'threshold' => ['threshold']],
            ],
            'class' => [
                ['warning' => ['max_warning'], 'error' => ['max_error'], 'threshold' => ['threshold']],
            ],
        ],

        // coupling.cbo (CboOptions: hierarchical class/namespace, bare keys,
        // no top-level legacy-flat branch).
        'coupling.cbo' => [
            'class' => [
                ['warning' => ['warning'], 'error' => ['error'], 'threshold' => ['threshold']],
            ],
            'namespace' => [
                ['warning' => ['warning'], 'error' => ['error'], 'threshold' => ['threshold']],
            ],
        ],

        // coupling.instability (InstabilityOptions: hierarchical
        // class/namespace, max_* graduated keys, no top-level legacy branch).
        'coupling.instability' => [
            'class' => [
                ['warning' => ['max_warning'], 'error' => ['max_error'], 'threshold' => ['threshold']],
            ],
            'namespace' => [
                ['warning' => ['max_warning'], 'error' => ['max_error'], 'threshold' => ['threshold']],
            ],
        ],

        // coupling.distance (DistanceOptions) — flat, max_distance_* graduated
        // keys paired with a bare `threshold` shorthand.
        'coupling.distance' => [
            '' => [
                ['warning' => ['max_distance_warning'], 'error' => ['max_distance_error'], 'threshold' => ['threshold']],
            ],
        ],

        // coupling.class-rank (ClassRankOptions) — flat, bare keys.
        'coupling.class-rank' => [
            '' => [['warning' => ['warning'], 'error' => ['error'], 'threshold' => ['threshold']]],
        ],

        // code-smell.long-parameter-list (LongParameterListOptions) — flat,
        // TWO independent dimensions at the same level: the bare pair and
        // the vo-prefixed pair (readonly VO constructor thresholds).
        'code-smell.long-parameter-list' => [
            '' => [
                ['warning' => ['warning'], 'error' => ['error'], 'threshold' => ['threshold']],
                ['warning' => ['vo_warning'], 'error' => ['vo_error'], 'threshold' => ['vo_threshold']],
            ],
        ],

        // code-smell.constructor-overinjection (ConstructorOverinjectionOptions) — flat, bare.
        'code-smell.constructor-overinjection' => [
            '' => [['warning' => ['warning'], 'error' => ['error'], 'threshold' => ['threshold']]],
        ],

        // code-smell.unreachable-code (UnreachableCodeOptions) — flat, bare.
        'code-smell.unreachable-code' => [
            '' => [['warning' => ['warning'], 'error' => ['error'], 'threshold' => ['threshold']]],
        ],

        // maintainability.index (MaintainabilityOptions) — flat, bare.
        'maintainability.index' => [
            '' => [['warning' => ['warning'], 'error' => ['error'], 'threshold' => ['threshold']]],
        ],

        // size.method-count / size.class-count / size.property-count — flat, bare.
        'size.method-count' => [
            '' => [['warning' => ['warning'], 'error' => ['error'], 'threshold' => ['threshold']]],
        ],
        'size.class-count' => [
            '' => [['warning' => ['warning'], 'error' => ['error'], 'threshold' => ['threshold']]],
        ],
        'size.property-count' => [
            '' => [['warning' => ['warning'], 'error' => ['error'], 'threshold' => ['threshold']]],
        ],

        // design.inheritance / design.noc / design.lcom / complexity.wmc — flat, bare.
        'design.inheritance' => [
            '' => [['warning' => ['warning'], 'error' => ['error'], 'threshold' => ['threshold']]],
        ],
        'design.noc' => [
            '' => [['warning' => ['warning'], 'error' => ['error'], 'threshold' => ['threshold']]],
        ],
        'design.lcom' => [
            '' => [['warning' => ['warning'], 'error' => ['error'], 'threshold' => ['threshold']]],
        ],
        'complexity.wmc' => [
            '' => [['warning' => ['warning'], 'error' => ['error'], 'threshold' => ['threshold']]],
        ],

        // duplication.code-duplication (CodeDuplicationOptions) — flat, bare.
        'duplication.code-duplication' => [
            '' => [['warning' => ['warning'], 'error' => ['error'], 'threshold' => ['threshold']]],
        ],
    ];
}
