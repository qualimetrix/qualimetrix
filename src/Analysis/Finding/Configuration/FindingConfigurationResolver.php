<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Configuration;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingCliOverrides;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfigurationResolverInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleOptionsDocument;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionThresholdShorthand;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionValueWrittenness;

final class FindingConfigurationResolver implements FindingConfigurationResolverInterface
{
    public function resolve(ConfigurationDocument $document, FindingCliOverrides $cliOverrides): FindingConfiguration
    {
        $rules = [];
        foreach ($document->contributions(ConfigSchema::RULES) as $contribution) {
            if (\is_array($contribution)) {
                $rules = self::mergeRules($rules, $contribution);
            }
        }

        $only = self::lastStringList($document->contributions(ConfigSchema::ONLY_RULES));
        $disabled = self::accumulatedStrings($document->contributions(ConfigSchema::DISABLED_RULES));

        return new FindingConfiguration(
            new RuleOptionsDocument($rules),
            $cliOverrides,
            new RuleSelection($only, $disabled),
        );
    }

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $overlay
     *
     * @return array<string, mixed>
     */
    private static function mergeRules(array $base, array $overlay): array
    {
        foreach ($overlay as $ruleName => $value) {
            $name = (string) $ruleName;
            if (self::isNestedMapOverlay($value, $base, $name)) {
                $base[$name] = self::mergeRuleOptions($name, '', $base[$name], $value);
                continue;
            }

            if (self::preservesWrittenValue($value, $base, $name)) {
                // An overlay's `~` on the rule's own name does not erase a
                // config a lower layer wrote for it — the same question, at
                // the rule-name level rather than the option-key level.
                // `false`/`true` are an explicit scalar switch and still
                // overwrite the base unconditionally: only `null` changes.
                continue;
            }

            $base[$name] = $value;
        }

        return $base;
    }

    /**
     * Unfolds both layers at `$path`, then merges them — kept as one method
     * because unfolding is scoped to the SAME path the merge recurses into
     * (`$path` grows one segment per nesting level), so splitting "unfold"
     * from "merge" into two top-level methods would just move the
     * recursion, not remove it. What this method does NOT do inline any
     * more is decide, per entry, whether to recurse or to keep an overlay's
     * `null` — {@see self::isNestedMapOverlay()} and
     * {@see self::preservesWrittenValue()} answer those, shared with
     * {@see self::mergeRules()}'s identical questions one level up.
     *
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $overlay
     *
     * @return array<array-key, mixed>
     */
    private static function mergeRuleOptions(string $ruleName, string $path, array $base, array $overlay): array
    {
        $base = RuleOptionThresholdShorthand::unfold($base, $ruleName, $path);
        $overlay = RuleOptionThresholdShorthand::unfold($overlay, $ruleName, $path);

        foreach ($overlay as $key => $value) {
            if (self::isNestedMapOverlay($value, $base, $key)) {
                $base[$key] = self::mergeRuleOptions($ruleName, self::childPath($path, $key), $base[$key], $value);
                continue;
            }

            if (self::preservesWrittenValue($value, $base, $key)) {
                // An overlay's `~` does not erase a value the layer below wrote.
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * Whether `$value` is a nested map overlay for `$key` — the entry must
     * recurse into a deeper merge rather than replace `$base[$key]` outright.
     *
     * @param array<array-key, mixed> $base
     */
    private static function isNestedMapOverlay(mixed $value, array $base, int|string $key): bool
    {
        return \is_array($value) && isset($base[$key]) && \is_array($base[$key]) && !array_is_list($value);
    }

    /**
     * Whether an overlay's `null` at `$key` must be left alone rather than
     * overwrite `$base[$key]` — an overlay's `~` does not erase a value a
     * lower layer wrote; `false`/`true` are an explicit scalar switch and
     * still overwrite unconditionally.
     *
     * @param array<array-key, mixed> $base
     */
    private static function preservesWrittenValue(mixed $value, array $base, int|string $key): bool
    {
        return $value === null
            && \array_key_exists($key, $base)
            && RuleOptionValueWrittenness::isWritten($base[$key]);
    }

    private static function childPath(string $path, int|string $key): string
    {
        return $path === '' ? (string) $key : $path . '.' . $key;
    }

    /**
     * @param list<mixed> $contributions
     *
     * @return list<string>
     */
    private static function lastStringList(array $contributions): array
    {
        $values = [];
        foreach ($contributions as $candidate) {
            if (\is_array($candidate) && array_is_list($candidate)) {
                $values = array_values(array_filter($candidate, is_string(...)));
            }
        }

        return $values;
    }

    /**
     * @param list<mixed> $contributions
     *
     * @return list<string>
     */
    private static function accumulatedStrings(array $contributions): array
    {
        $values = [];
        foreach ($contributions as $candidate) {
            if (\is_array($candidate) && array_is_list($candidate)) {
                array_push($values, ...array_filter($candidate, is_string(...)));
            }
        }

        return array_values(array_unique($values));
    }
}
