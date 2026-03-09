<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Console;

use AiMessDetector\Configuration\RuleOptionsParser;
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
     * @return array<string, array<string, mixed>>
     */
    public function parseRuleOptions(InputInterface $input): array
    {
        $ruleOptions = [];

        // Parse generic --rule-opt options
        $genericOptions = $input->getOption('rule-opt');
        if (\is_array($genericOptions)) {
            /** @var list<string> $genericOptions */
            $ruleOptions = $this->ruleOptionsParser->parseRuleOptions($genericOptions);
        }

        // Parse all registered short aliases (from rule definitions)
        foreach ($this->ruleOptionsParser->getAliasNames() as $alias) {
            $value = $input->getOption($alias);

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

            if (!isset($ruleOptions[$ruleName])) {
                $ruleOptions[$ruleName] = [];
            }

            // Short aliases have lower priority than --rule-opt
            if (!isset($ruleOptions[$ruleName][$optionName])) {
                $ruleOptions[$ruleName][$optionName] = $parsed['value'];
            }
        }

        return $ruleOptions;
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
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return $value;
    }
}
