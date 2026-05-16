<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Configuration\RuleOptionsParser;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Parses CLI options from Symfony Console InputInterface.
 */
final readonly class CliOptionsParser
{
    public function __construct(
        private RuleOptionsParser $ruleOptionsParser,
    ) {}

    /**
     * Parse CLI input into rule options.
     *
     * Defensive about option presence: commands other than `check` (e.g.
     * `debug:layer-assignment`) reuse {@see RuntimeConfigurator}, which calls
     * this parser, but do not expose `--rule-opt` or per-rule short aliases.
     * Missing options are treated as "no value supplied".
     *
     * @return array<string, array<string, mixed>>
     */
    public function parseRuleOptions(InputInterface $input): array
    {
        /** @var list<string> $genericOptions */
        $genericOptions = $this->optionValue($input, 'rule-opt', []);
        $ruleOptions = \is_array($genericOptions)
            ? $this->ruleOptionsParser->parseRuleOptions($genericOptions)
            : [];

        // Parse all registered short aliases (from rule definitions)
        foreach ($this->ruleOptionsParser->getAliasNames() as $alias) {
            $value = $this->optionValue($input, $alias);

            // VALUE_REQUIRED: null when not provided; VALUE_NONE: false when not provided
            if ($value === null || $value === false) {
                continue;
            }

            $parsed = $this->ruleOptionsParser->parseShortAlias($alias, $this->normalizeValue($value));
            if ($parsed === null) {
                continue;
            }

            $ruleName = $parsed['rule'];
            $optionName = $parsed['option'];

            // Short aliases have lower priority than --rule-opt
            $ruleOptions[$ruleName] ??= [];
            $ruleOptions[$ruleName][$optionName] ??= $parsed['value'];
        }

        return $ruleOptions;
    }

    /**
     * Returns the option value when the input defines it, or `$default` when
     * the command does not expose this option at all.
     *
     * Centralising the `hasOption()` guard keeps {@see parseRuleOptions()}
     * focused on the parsing flow.
     */
    private function optionValue(InputInterface $input, string $name, mixed $default = null): mixed
    {
        return $input->hasOption($name) ? $input->getOption($name) : $default;
    }

    /**
     * Normalizes a CLI option value to the appropriate PHP type.
     *
     * Handles boolean strings ('true'/'false'), floats, and integers.
     */
    private function normalizeValue(mixed $value): mixed
    {
        if (!\is_string($value)) {
            return $value;
        }

        // Boolean strings
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }

        // Numeric: float (contains dot) vs int
        if (is_numeric($value)) {
            return str_contains($value, '.') || stripos($value, 'e') !== false ? (float) $value : (int) $value;
        }

        return $value;
    }
}
