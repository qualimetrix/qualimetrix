<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Pipeline;

use Qualimetrix\Configuration\ConfigSchema;

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
        ConfigSchema::EXCLUDES,
        ConfigSchema::EXCLUDE_HEALTH,
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
                    $base[$key] = self::deepMergeAssociative($base[$key], $value);
                    continue;
                }
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * Deep-merges two associative arrays, replacing list-arrays entirely.
     *
     * Unlike array_replace_recursive, this correctly handles list-valued options
     * (e.g., exclude_namespaces): lists are replaced, not merged by index.
     *
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $overlay
     *
     * @return array<array-key, mixed>
     */
    private static function deepMergeAssociative(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if (\is_array($value) && isset($base[$key]) && \is_array($base[$key])
                && !array_is_list($value)
            ) {
                $base[$key] = self::deepMergeAssociative($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
