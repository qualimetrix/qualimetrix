<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration;

/**
 * Parses CLI rule options.
 *
 * Supports:
 * - Unified format: --rule-opt=RULE:OPTION=VALUE
 * - Short aliases defined by rules (e.g., --cyclomatic-warning=N)
 */
final readonly class RuleOptionsParser
{
    /**
     * @param array<string, array{rule: string, option: string}> $shortAliases
     */
    public function __construct(
        private array $shortAliases = [],
    ) {}

    /**
     * Parses --rule-opt format options.
     *
     * @param list<string> $ruleOpts List of rule options in format "RULE:OPTION=VALUE"
     *
     * @return array<string, array<string, mixed>> Parsed options grouped by rule
     */
    public function parseRuleOptions(array $ruleOpts): array
    {
        $result = [];

        foreach ($ruleOpts as $opt) {
            $parsed = $this->parseRuleOption($opt);
            if ($parsed === null) {
                continue;
            }

            [$ruleName, $option, $value] = $parsed;

            if (!isset($result[$ruleName])) {
                $result[$ruleName] = [];
            }

            $result[$ruleName][$option] = $value;
        }

        return $result;
    }

    /**
     * Returns list of all registered short alias names.
     *
     * @return list<string>
     */
    public function getAliasNames(): array
    {
        return array_keys($this->shortAliases);
    }

    /**
     * Parses a short alias option.
     *
     * @return array{rule: string, option: string, value: mixed}|null
     */
    public function parseShortAlias(string $alias, mixed $value): ?array
    {
        $mapping = $this->shortAliases[$alias] ?? null;
        if ($mapping === null) {
            return null;
        }

        return [
            'rule' => $mapping['rule'],
            'option' => $mapping['option'],
            'value' => $value,
        ];
    }

    /**
     * Parses disabled rules from CLI.
     *
     * @param list<string> $disableRules List of rule names to disable
     *
     * @return list<string> Normalized rule names
     */
    public function parseDisabledRules(array $disableRules): array
    {
        return array_values(array_map(
            fn(string $rule) => $this->normalizeRuleName($rule),
            $disableRules,
        ));
    }

    /**
     * Parses only rules from CLI.
     *
     * @param list<string> $onlyRules List of rule names to run
     *
     * @return list<string> Normalized rule names
     */
    public function parseOnlyRules(array $onlyRules): array
    {
        return array_values(array_map(
            fn(string $rule) => $this->normalizeRuleName($rule),
            $onlyRules,
        ));
    }

    /**
     * Parses a single rule option.
     *
     * @return array{0: string, 1: string, 2: mixed}|null [ruleName, option, value] or null if invalid
     */
    private function parseRuleOption(string $opt): ?array
    {
        // Format: RULE:OPTION=VALUE
        if (!str_contains($opt, ':') || !str_contains($opt, '=')) {
            return null;
        }

        $colonPos = strpos($opt, ':');
        if ($colonPos === false) {
            return null;
        }

        $ruleName = $this->normalizeRuleName(substr($opt, 0, $colonPos));
        $rest = substr($opt, $colonPos + 1);

        $equalsPos = strpos($rest, '=');
        if ($equalsPos === false) {
            return null;
        }

        $option = $this->normalizeOptionName(substr($rest, 0, $equalsPos));
        $value = $this->normalizeValue(substr($rest, $equalsPos + 1));

        return [$ruleName, $option, $value];
    }

    /**
     * Normalizes rule name to kebab-case.
     */
    private function normalizeRuleName(string $name): string
    {
        return strtolower(trim($name));
    }

    /**
     * Normalizes option name from kebab-case to camelCase.
     */
    private function normalizeOptionName(string $name): string
    {
        $name = trim($name);

        return lcfirst(str_replace(['-', '_'], '', ucwords($name, '-_')));
    }

    /**
     * Normalizes value to appropriate type.
     */
    private function normalizeValue(string $value): mixed
    {
        $value = trim($value);

        // Boolean
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }

        // Integer
        if (ctype_digit($value) || (str_starts_with($value, '-') && ctype_digit(substr($value, 1)))) {
            return (int) $value;
        }

        // Float
        if (is_numeric($value)) {
            return (float) $value;
        }

        // String
        return $value;
    }
}
