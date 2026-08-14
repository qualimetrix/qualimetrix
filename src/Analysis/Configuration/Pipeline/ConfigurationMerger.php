<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Pipeline;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionThresholdModeResolver;

/**
 * Centralizes configuration merge logic for layered configuration resolution.
 *
 * Used by both {@see ConfigurationPipeline} (merging stage layers) and
 * {@see Stage\PresetStage} (merging multiple presets into a single layer).
 *
 * Merge strategy per key type:
 * - {@see self::MERGEABLE_LIST_KEYS}: union semantics — values accumulate across layers
 *   and are deduplicated. This is appropriate for additive filters like disabled_rules
 *   and exclude_paths where each layer can contribute additional entries.
 * - `rules`: deep associative merge — nested associative arrays are merged recursively,
 *   while list-valued options (e.g., exclude_namespaces) are replaced entirely.
 *   This allows a later layer to override individual rule options without losing
 *   unrelated rule configurations from earlier layers.
 * - Everything else: simple override — the overlay value replaces the base value.
 *
 * **Why `only_rules` is NOT in MERGEABLE_LIST_KEYS:**
 * `only_rules` is a restrictive filter ("run only these rules"). Union semantics
 * would widen the scope with each layer, defeating the purpose of restriction.
 * Instead, a later layer's `only_rules` completely replaces the earlier one,
 * so the most specific (highest-priority) layer has full control over the allowlist.
 */
final class ConfigurationMerger
{
    /**
     * Keys whose values use union/accumulation semantics across layers.
     *
     * These are additive list keys where each configuration layer can contribute
     * additional entries. Values are merged and deduplicated.
     *
     * Notable exclusion: `only_rules` — a restrictive filter where union would
     * widen the scope, contradicting the intent of "only these rules".
     *
     * @var list<string>
     */
    public const array MERGEABLE_LIST_KEYS = [
        ConfigSchema::DISABLED_RULES,
        ConfigSchema::EXCLUDE_PATHS,
        ConfigSchema::EXCLUDE_NAMESPACES,
        ConfigSchema::EXCLUDES,
    ];

    /**
     * Merges an overlay configuration layer into a base configuration.
     *
     * @param array<string, mixed> $base Accumulated configuration from earlier layers
     * @param array<string, mixed> $overlay New layer to merge on top
     *
     * @return array<string, mixed> Merged configuration
     */
    public static function merge(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if (\is_array($value) && isset($base[$key]) && \is_array($base[$key])) {
                if (\in_array($key, self::MERGEABLE_LIST_KEYS, true)) {
                    $base[$key] = array_values(array_unique(array_merge($base[$key], $value)));
                    continue;
                }

                if ($key === ConfigSchema::RULES) {
                    $base[$key] = self::deepMergeAllRuleOptions($base[$key], $value);
                    continue;
                }
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * Deep-merges the `rules:` section (all rule names) — the top-level
     * keys here are rule slugs (`size.method-count`, `complexity.cyclomatic`,
     * ...), so this dispatches each rule to {@see deepMergeRuleOptions()}
     * with its name and an empty nesting path, then that function recurses
     * per-rule.
     *
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $overlay
     *
     * @return array<array-key, mixed>
     */
    private static function deepMergeAllRuleOptions(array $base, array $overlay): array
    {
        foreach ($overlay as $ruleName => $value) {
            if (\is_array($value) && isset($base[$ruleName]) && \is_array($base[$ruleName])
                && !array_is_list($value)
            ) {
                $base[$ruleName] = self::deepMergeRuleOptions((string) $ruleName, '', $base[$ruleName], $value);
            } else {
                $base[$ruleName] = $value;
            }
        }

        return $base;
    }

    /**
     * Deep-merges a single rule's option array, evicting `threshold` vs
     * `warning`/`error` mode conflicts across the merge boundary before
     * recursively merging associative sub-arrays while replacing list-valued
     * options wholesale. See {@see RuleOptionThresholdModeResolver} for
     * why this can't just be a plain deep-merge.
     *
     * Recurses so hierarchical rule levels (e.g. `complexity.cyclomatic`'s
     * `callable:`/`class:`) get eviction scoped to the level the conflicting
     * keys actually live at, not the rule's top level — `$path` tracks the
     * dot-joined nesting (`''`, `'method'`, `'class'`, ...) consulted by
     * {@see RuleThresholdKeyGroupRegistry}.
     *
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $overlay
     *
     * @return array<array-key, mixed>
     */
    private static function deepMergeRuleOptions(string $ruleName, string $path, array $base, array $overlay): array
    {
        $base = RuleOptionThresholdModeResolver::evictOverriddenMode($base, $overlay, $ruleName, $path);

        foreach ($overlay as $key => $value) {
            if (\is_array($value) && isset($base[$key]) && \is_array($base[$key])
                && !array_is_list($value)
            ) {
                $childPath = $path === '' ? (string) $key : $path . '.' . $key;
                $base[$key] = self::deepMergeRuleOptions($ruleName, $childPath, $base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

}
