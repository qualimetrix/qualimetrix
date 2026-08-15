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
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionThresholdModeResolver;

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
            if (\is_array($value) && isset($base[$name]) && \is_array($base[$name]) && !array_is_list($value)) {
                $base[$name] = self::mergeRuleOptions($name, '', $base[$name], $value);
                continue;
            }

            $base[$name] = $value;
        }

        return $base;
    }

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $overlay
     *
     * @return array<array-key, mixed>
     */
    private static function mergeRuleOptions(string $ruleName, string $path, array $base, array $overlay): array
    {
        $base = RuleOptionThresholdModeResolver::evictOverriddenMode($base, $overlay, $ruleName, $path);
        foreach ($overlay as $key => $value) {
            if (\is_array($value) && isset($base[$key]) && \is_array($base[$key]) && !array_is_list($value)) {
                $childPath = $path === '' ? (string) $key : $path . '.' . $key;
                $base[$key] = self::mergeRuleOptions($ruleName, $childPath, $base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
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
