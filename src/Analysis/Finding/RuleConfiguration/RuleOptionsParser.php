<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration;

use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\RetiredSuppressionOptions;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionSurface;

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
     * @param array<string, class-string<RuleOptionsInterface>> $optionsClasses
     */
    public function __construct(
        private array $shortAliases = [],
        private array $optionsClasses = [],
    ) {}

    /**
     * Returns list of all registered short alias names.
     *
     * @return list<string>
     */
    public function getAliasNames(): array
    {
        return array_keys($this->shortAliases);
    }

    public function surfaceFor(string $rule): ?RuleOptionSurface
    {
        $class = $this->optionsClasses[$rule] ?? null;

        return $class === null ? null : RuleOptionSurface::of($class);
    }

    /** @return array{rule: string, option: string, text: string} */
    public function parseAuthoredRuleOption(string $text): array
    {
        $colon = strpos($text, ':');
        $equals = $colon === false ? false : strpos($text, '=', $colon + 1);
        if ($colon === false || $colon === 0 || $equals === false || $equals <= $colon + 1) {
            throw ConfigurationRefusal::aboutCommandLineInput('--rule-opt', \sprintf('Invalid --rule-opt "%s". Expected RULE:OPTION=VALUE.', $text));
        }
        $rule = substr($text, 0, $colon);
        $authoredOption = substr($text, $colon + 1, $equals - $colon - 1);
        RetiredSuppressionOptions::refuseRuleOption([trim($authoredOption) => null]);
        $value = substr($text, $equals + 1);
        if (trim($value) === '') {
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--rule-opt',
                \sprintf(
                    'Option "%s" of rule "%s" was written with an empty value ("--rule-opt %s"). '
                    . 'Write a value after "=", or omit this --rule-opt entry entirely to use the option\'s default.',
                    ConfigKeySpelling::normalize($authoredOption),
                    $rule,
                    $text,
                ),
            );
        }

        return ['rule' => $rule, 'option' => $authoredOption, 'text' => $value];
    }

    /**
     * The rule/option a short alias resolves to, without attaching a value.
     *
     * @return array{rule: string, option: string}|null
     */
    public function aliasTarget(string $alias): ?array
    {
        $mapping = $this->shortAliases[$alias] ?? null;
        if ($mapping === null) {
            return null;
        }

        return [
            'rule' => $mapping['rule'],
            'option' => ConfigKeySpelling::normalize($mapping['option']),
        ];
    }

    /** @return list<string> Every producer whose options the parser can address. */
    public function producerNames(): array
    {
        return array_keys($this->optionsClasses);
    }
}
