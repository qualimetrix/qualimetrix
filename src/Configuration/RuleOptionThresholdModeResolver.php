<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration;

/**
 * Resolves `threshold` vs `warning`/`error` "mode" conflicts that arise when
 * two configuration layers (preset, config file, CLI) are merged.
 *
 * {@see \Qualimetrix\Rules\Support\ThresholdParser} rejects an option array
 * that contains both a `threshold` key and a `warning`/`error` key for the
 * same option group — the two are mutually exclusive spellings of the same
 * concept. That guard is correct for a single configuration source, but a
 * naive deep-merge of two *different* sources (e.g. a `strict` preset that
 * sets `warning`/`error`, overridden by a `--rule-opt=...:threshold=25` CLI
 * flag) lets both keys survive into the same array and trips the guard even
 * though the higher-priority layer clearly meant to switch modes, not to
 * combine them.
 *
 * {@see evictOverriddenMode()} is called by the merge functions in
 * {@see \Qualimetrix\Configuration\Pipeline\ConfigurationMerger} (preset ↔
 * config file, and multi-preset merging) and
 * {@see \Qualimetrix\Configuration\RuleOptionsFactory} (config file ↔ CLI)
 * before they merge two layers together: it strips the *lower*-priority
 * layer's keys that belong to the mode the *higher*-priority layer just
 * switched away from, for the same option group, at the same nesting level.
 * A conflict that originates within a single layer (both keys set by the
 * same source) is left untouched, since only the lower-priority side is
 * ever modified — it still reaches `ThresholdParser` and is reported as a
 * genuine configuration error.
 *
 * ## Grouping: declared first, heuristic only as an unreliable fallback
 *
 * Which keys belong to the same "group" (a `threshold` shorthand and the
 * `warning`/`error` pair it's shorthand for) is rule-specific — some rules
 * use the bare {@see \Qualimetrix\Core\Rule\RuleOptionKey} spellings, others
 * use a prefixed graduated pair (`max_warning`/`max_error`) with a *bare*
 * `threshold` shorthand, and some (`code-smell.long-parameter-list`) have
 * two independent groups at the same nesting level. This method ALWAYS
 * checks {@see RuleThresholdKeyGroupRegistry} first for the given
 * `$ruleName`/`$path`: if an entry exists, grouping is resolved by exact
 * (case/separator-insensitive) key lookup against that entry — no guessing.
 *
 * Only when the registry has no entry for that `$ruleName`/`$path` does
 * this fall back to {@see groupsOfByHeuristic()}, which *guesses* a key's
 * group by matching a `threshold`/`warning`/`error` suffix and grouping by
 * the prefix before it. That heuristic is unreliable in at least two known
 * ways and exists only as a safety net for a rule not yet in the registry:
 * - it cannot tell a genuine `threshold`-shorthand key from a legacy alias
 *   that merely *ends* in the substring "Threshold" while representing a
 *   `warning`/`error` value (e.g. `warningThreshold` — see the registry's
 *   `complexity.cyclomatic`/`cognitive`/`npath` top-level entries, which
 *   exist specifically to correct this);
 * - it requires the threshold key's prefix to match the graduated keys'
 *   prefix exactly, which several real Options classes don't follow (a
 *   bare `threshold` paired with a prefixed `max_warning`/`max_error`) —
 *   for such a rule with no registry entry, eviction simply would not fire
 *   for that mismatched pair, which is the same "cannot mix" failure this
 *   whole mechanism exists to prevent.
 *
 * Every rule known to this codebase has a registry entry (see
 * {@see RuleThresholdKeyGroupRegistry} for the full list and rationale), so
 * this heuristic is not actually exercised for any of them today.
 */
final class RuleOptionThresholdModeResolver
{
    /** @var list<string> */
    private const array HEURISTIC_MARKERS = ['threshold', 'warning', 'error'];

    /**
     * Removes keys from $base that belong to the threshold "mode" the
     * $overlay array is switching away from, for any option group present
     * in both — at this array's nesting level only. Callers recurse into
     * nested associative sub-arrays (e.g. `method:`/`class:` levels of a
     * hierarchical rule) and call this again at each level, passing the
     * corresponding `$path` (`''` for the rule's own top level, `'method'`,
     * `'class'`, `'namespace'`, ... for nested levels).
     *
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $overlay
     *
     * @return array<array-key, mixed>
     */
    public static function evictOverriddenMode(array $base, array $overlay, string $ruleName, string $path): array
    {
        $declaredGroups = RuleThresholdKeyGroupRegistry::groupsFor($ruleName, $path);

        return $declaredGroups !== []
            ? self::evictUsingDeclaredGroups($base, $overlay, $declaredGroups)
            : self::evictUsingHeuristic($base, $overlay);
    }

    /**
     * Mode-conflict eviction driven by an explicit, unambiguous group
     * declaration from {@see RuleThresholdKeyGroupRegistry} — no guessing.
     *
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $overlay
     * @param list<array{warning: list<string>, error: list<string>, threshold: list<string>}> $groups
     *
     * @return array<array-key, mixed>
     */
    private static function evictUsingDeclaredGroups(array $base, array $overlay, array $groups): array
    {
        foreach ($groups as $group) {
            $overlayHasThreshold = self::containsAnyNormalized($overlay, $group['threshold']);
            $overlayHasGraduated = self::containsAnyNormalized($overlay, $group['warning'])
                || self::containsAnyNormalized($overlay, $group['error']);

            if ($overlayHasGraduated) {
                $base = self::removeAnyNormalized($base, $group['threshold']);
            }

            if ($overlayHasThreshold) {
                $base = self::removeAnyNormalized($base, [...$group['warning'], ...$group['error']]);
            }
        }

        return $base;
    }

    /**
     * @param array<array-key, mixed> $config
     * @param list<string> $candidateKeys
     */
    private static function containsAnyNormalized(array $config, array $candidateKeys): bool
    {
        $normalizedCandidates = array_map(self::normalize(...), $candidateKeys);

        foreach (array_keys($config) as $key) {
            if (\in_array(self::normalize((string) $key), $normalizedCandidates, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $config
     * @param list<string> $candidateKeys
     *
     * @return array<array-key, mixed>
     */
    private static function removeAnyNormalized(array $config, array $candidateKeys): array
    {
        $normalizedCandidates = array_map(self::normalize(...), $candidateKeys);

        foreach (array_keys($config) as $key) {
            if (\in_array(self::normalize((string) $key), $normalizedCandidates, true)) {
                unset($config[$key]);
            }
        }

        return $config;
    }

    /**
     * UNRELIABLE fallback for a rule/path with no {@see RuleThresholdKeyGroupRegistry}
     * entry — see this class's docblock for the two known failure modes.
     * Guesses grouping by suffix (`threshold`/`warning`/`error`) and prefix
     * match, case/separator-insensitively.
     *
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $overlay
     *
     * @return array<array-key, mixed>
     */
    private static function evictUsingHeuristic(array $base, array $overlay): array
    {
        $overlayGroups = self::heuristicGroupsOf($overlay);

        if ($overlayGroups === []) {
            return $base;
        }

        foreach (array_keys($base) as $key) {
            $classified = self::heuristicClassify((string) $key);
            if ($classified === null) {
                continue;
            }

            [$marker, $prefix] = $classified;
            $overlayMarkers = $overlayGroups[$prefix] ?? null;
            if ($overlayMarkers === null) {
                continue;
            }

            $baseIsThreshold = $marker === 'threshold';
            $overlayHasThreshold = isset($overlayMarkers['threshold']);
            $overlayHasGraduated = isset($overlayMarkers['warning']) || isset($overlayMarkers['error']);

            if ($baseIsThreshold && $overlayHasGraduated) {
                unset($base[$key]);
            } elseif (!$baseIsThreshold && $overlayHasThreshold) {
                unset($base[$key]);
            }
        }

        return $base;
    }

    /**
     * Groups a config array's threshold-mode marker keys by their prefix
     * (heuristic fallback only — see class docblock).
     *
     * @param array<array-key, mixed> $config
     *
     * @return array<string, array<string, true>> Prefix => set of markers present
     */
    private static function heuristicGroupsOf(array $config): array
    {
        $groups = [];

        foreach (array_keys($config) as $key) {
            $classified = self::heuristicClassify((string) $key);
            if ($classified === null) {
                continue;
            }

            [$marker, $prefix] = $classified;
            $groups[$prefix][$marker] = true;
        }

        return $groups;
    }

    /**
     * Classifies a config key as a threshold-mode marker, if it is one
     * (heuristic fallback only — see class docblock for why this guesses
     * wrong for legacy `*Threshold`-suffixed warning/error aliases).
     *
     * @return array{0: string, 1: string}|null [marker, groupPrefix]
     */
    private static function heuristicClassify(string $key): ?array
    {
        $normalized = self::normalize($key);

        foreach (self::HEURISTIC_MARKERS as $marker) {
            if (str_ends_with($normalized, $marker)) {
                return [$marker, substr($normalized, 0, -\strlen($marker))];
            }
        }

        return null;
    }

    private static function normalize(string $key): string
    {
        return strtolower(str_replace(['_', '-'], '', $key));
    }
}
