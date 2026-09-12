<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration;

use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;

/**
 * Unfolds a `threshold` shorthand into the graduated `warning`/`error` pair it
 * stands for, inside ONE configuration layer.
 *
 * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser} rejects an option array
 * in which both a `threshold` key and a `warning`/`error` key of the same
 * option group carry a value — the two are mutually exclusive spellings of the
 * same concept. That guard is correct for a single configuration source, but
 * two *different* layers (preset, config file, CLI) are merged before
 * `fromArray()` ever runs, and each is allowed to pick its own spelling for
 * the same group. A naive deep-merge that concatenated both layers' keys
 * verbatim would carry both spellings into the merged array and trip the
 * guard even though each layer, on its own, said something coherent.
 *
 * {@see self::unfold()} is called by Finding-owned configuration folding and
 * {@see \Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory} (config file ↔ CLI)
 * on EACH layer, before the two are merged: it rewrites that one layer's
 * `threshold` key, if it carries one, into the pair of graduated keys the
 * declared group names — so that by the time the two layers meet, neither
 * carries the shorthand any more and a plain key-for-key merge already does
 * the right thing in both directions (a `threshold` layer overriding a
 * graduated one, and a graduated layer overriding a `threshold` one).
 *
 * This replaces an earlier design (`RuleOptionThresholdModeResolver`) that
 * evicted the LOWER layer's keys of the mode the higher layer switched away
 * from. Its signature took base and overlay and returned only base, which is
 * exactly why it could not unfold both sides — "unfold each layer" had no
 * base/overlay asymmetry left to express. Unfolding both layers before they
 * merge removes that asymmetry: no layer that reaches the merge ever carries
 * a shorthand, so there is nothing left to evict either way.
 *
 * ## Grouping is declared, never guessed
 *
 * Which keys belong to the same "group" (a `threshold` shorthand and the
 * `warning`/`error` pair it is shorthand for) is rule-specific — some rules
 * use the bare {@see \Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey} spellings, others
 * use a prefixed graduated pair (`max_warning`/`max_error`) with a *bare*
 * `threshold` shorthand, and some (`code-smell.long-parameter-list`) have two
 * independent groups at the same nesting level. This method looks the group
 * up in {@see RuleThresholdKeyGroupRegistry} for the given `$ruleName`/`$path`
 * — an entry mirrors, rather than reinterprets, the corresponding
 * `ThresholdParser::parse()` call. A rule/path with no entry is left
 * completely untouched: unfolding a shorthand it cannot enumerate would be a
 * guess, and a wrong guess would fabricate a key rather than merely fail to
 * remove one. {@see RuleThresholdKeyGroupRegistryCompletenessTest} proves,
 * mechanically and from two independent sources, that every rule/path this
 * codebase actually calls `ThresholdParser::parse()` at has an entry, so this
 * is not a live gap.
 */
final class RuleOptionThresholdShorthand
{
    /**
     * Rewrites `$layer`'s `threshold` key, for every declared group of
     * `($ruleName, $path)`, into the graduated `warning`/`error` pair it is
     * shorthand for — leaving every other key untouched. Applied to ONE
     * configuration layer; callers apply it to both layers of a merge, before
     * merging, at every nesting level (`$path` tracks the dot-joined nesting:
     * `''`, `'callable'`, `'class'`, ...).
     *
     * Five conditions gate the rewrite, all necessary:
     *
     * 1. The group's `threshold` key must be WRITTEN — carry a non-null value
     *    — not merely present: a `threshold: ~` selects no mode and leaves
     *    the layer alone (`ThresholdParser` has treated `~` this way since
     *    X19).
     * 2. Its value must be of the group's declared `form`
     *    ({@see RuleThresholdKeyGroupRegistry} — the SAME scalar form the
     *    graduated pair itself declares, verified by
     *    `RuleThresholdKeyGroupRegistryCompletenessTest`): unfolding rewrites
     *    one key into two, so it must never change WHICH key a refusal names.
     *    `threshold: abc` unfolded would reach the recognition seam as
     *    `warning: abc` and refuse the author for a key they never wrote.
     *    Left alone, it reaches the seam under its own name and is refused
     *    there as `threshold`.
     * 3. The SAME layer must not already carry a graduated key of the group:
     *    that is a within-one-layer mix, and it must keep reaching
     *    `ThresholdParser` to be refused as a genuine configuration error.
     * 4. The group must actually declare a graduated pair to unfold into —
     *    the three `LONE_THRESHOLD` entries declare empty `warning`/`error`
     *    lists on purpose (their shorthand is cross-path and switches a
     *    sibling level off; bare `warning`/`error` are not accepted at that
     *    path at all), so there is nothing to unfold into.
     * 5. The key written is the FOLDED spelling
     *    ({@see ConfigKeySpelling::normalize()}). A merge afterwards compares
     *    keys by literal equality, so a graduated key this method did NOT
     *    write — because the layer never carried a threshold shorthand to
     *    unfold — must still end up spelled the same way, or it would
     *    collide with nothing and leave two keys for the same concept alive
     *    side by side. Rather than trust every door to fold separators away
     *    before either merge site runs, this method folds the group's own
     *    warning/error keys in $layer to their canonical spelling itself,
     *    whether or not it unfolds a shorthand this call — see
     *    {@see self::foldGroupKeySpelling()}.
     *
     * @param array<array-key, mixed> $layer
     *
     * @return array<array-key, mixed>
     */
    public static function unfold(array $layer, string $ruleName, string $path): array
    {
        foreach (RuleThresholdKeyGroupRegistry::groupsFor($ruleName, $path) as $group) {
            if ($group['warning'] === [] && $group['error'] === []) {
                // Condition 4: LONE_THRESHOLD — nothing to unfold into.
                continue;
            }

            $layer = self::foldGroupKeySpelling($layer, $group['warning']);
            $layer = self::foldGroupKeySpelling($layer, $group['error']);

            $thresholdKey = self::firstWrittenKey($layer, $group['threshold']);
            if ($thresholdKey === null) {
                // Condition 1: absent, or written `~` — leaves no mode selected.
                continue;
            }

            if (
                self::firstWrittenKey($layer, $group['warning']) !== null
                || self::firstWrittenKey($layer, $group['error']) !== null
            ) {
                // Condition 3: within-one-layer mix — leave for ThresholdParser to refuse.
                // Asks the same "written, not present" question as condition 1: a
                // graduated key present but written `~` selected no mode either, and
                // must not block unfolding the threshold shorthand beside it.
                continue;
            }

            $value = $layer[$thresholdKey];
            if (!$group['form']->accepts($value)) {
                // Condition 2: not the scalar form the graduated pair takes —
                // leave under its own name to be refused there.
                continue;
            }

            unset($layer[$thresholdKey]);
            // Condition 5: write the folded spelling.
            $layer[ConfigKeySpelling::normalize($group['warning'][0])] = $value;
            $layer[ConfigKeySpelling::normalize($group['error'][0])] = $value;
        }

        return $layer;
    }

    /**
     * Renames whichever of $candidateKeys is present in $layer, under
     * whatever spelling it was written, to the FOLDED spelling of the first
     * candidate — the same spelling {@see self::unfold()} itself writes a
     * shorthand's unfolded pair under. Makes condition 5 hold independently
     * of whether every door already folds separators away: a layer that
     * never carries a threshold shorthand at all still leaves this group's
     * own graduated keys collision-ready for a merge against another layer
     * that unfolded into them.
     *
     * A no-op when the key found already is the canonical spelling, when no
     * candidate is present, or (deliberately) when more than one candidate
     * spelling of the same concept is present at once — that is a
     * within-one-layer authoring mistake for {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser}
     * to refuse, not something this fold should silently resolve by picking
     * one.
     *
     * @param array<array-key, mixed> $layer
     * @param list<string> $candidateKeys
     *
     * @return array<array-key, mixed>
     */
    private static function foldGroupKeySpelling(array $layer, array $candidateKeys): array
    {
        if ($candidateKeys === []) {
            return $layer;
        }

        $canonical = ConfigKeySpelling::normalize($candidateKeys[0]);
        $normalizedCandidates = array_map(ConfigKeySpelling::normalize(...), $candidateKeys);

        $present = [];
        foreach (array_keys($layer) as $key) {
            if (\in_array(ConfigKeySpelling::normalize((string) $key), $normalizedCandidates, true)) {
                $present[] = $key;
            }
        }

        if (\count($present) !== 1) {
            // Absent, or more than one spelling present at once — leave for
            // ThresholdParser to refuse the latter as a genuine mix.
            return $layer;
        }

        $key = $present[0];
        if ((string) $key === $canonical) {
            return $layer;
        }

        $value = $layer[$key];
        unset($layer[$key]);
        $layer[$canonical] = $value;

        return $layer;
    }

    /**
     * Returns the first candidate key written with a non-null value, or null
     * when every candidate is either absent or written `~`.
     *
     * @param array<array-key, mixed> $config
     * @param list<string> $candidateKeys
     */
    private static function firstWrittenKey(array $config, array $candidateKeys): ?string
    {
        $normalizedCandidates = array_map(ConfigKeySpelling::normalize(...), $candidateKeys);

        foreach ($config as $key => $value) {
            if (
                \in_array(ConfigKeySpelling::normalize((string) $key), $normalizedCandidates, true)
                && RuleOptionValueWrittenness::isWritten($value)
            ) {
                return (string) $key;
            }
        }

        return null;
    }
}
