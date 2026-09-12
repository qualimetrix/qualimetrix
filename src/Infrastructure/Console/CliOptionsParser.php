<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParser;
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

            $this->refuseEmptyAliasValue($alias, $value);

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
     * Refuses a short alias written with an empty value (`--some-alias=`).
     *
     * The alias resolves to the same rule/option pair {@see RuleOptionsParser::normalizeValue()}
     * guards against on the sibling `--rule-opt` door: this door also carries
     * text only, so an empty value is never a genuine "explicitly nothing" —
     * it is the same one-element-empty-string defect the CLI cannot express
     * on purpose (see `promise-effect/promise-ledger.tsv`, `cli-alias` rows).
     * Applied uniformly to every alias, including the sixteen whose door-null
     * meaning no external carrier documents: the reasoning is the same
     * regardless of whether the alias appears in the CLI options table, and
     * this door has one code path for all of them.
     */
    private function refuseEmptyAliasValue(string $alias, mixed $value): void
    {
        if (!\is_string($value) || trim($value) !== '') {
            return;
        }

        $target = $this->ruleOptionsParser->aliasTarget($alias);
        $targetPhrase = $target !== null
            ? \sprintf(' (rule "%s", option "%s")', $target['rule'], $target['option'])
            : '';

        throw ConfigurationRefusal::aboutCommandLineInput(
            '--' . $alias,
            \sprintf(
                'Option --%s%s was written with an empty value ("--%s="). '
                . 'Write a value after "=", or omit --%s entirely to use its default.',
                $alias,
                $targetPhrase,
                $alias,
                $alias,
            ),
        );
    }

    /**
     * Normalizes a CLI option value to the appropriate PHP type.
     *
     * Handles boolean strings ('true'/'false'), floats, and integers.
     *
     * Never receives an empty string: its only caller runs
     * {@see self::refuseEmptyAliasValue()} first.
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
