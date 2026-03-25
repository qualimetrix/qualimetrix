<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration;

use Qualimetrix\Core\Rule\RuleInterface;
use ReflectionClass;

/**
 * Creates RuleOptionsParser with short aliases collected from rules.
 */
final readonly class RuleOptionsParserFactory
{
    /**
     * Creates parser with aliases from given rules.
     *
     * @param iterable<RuleInterface> $rules
     */
    public function create(iterable $rules): RuleOptionsParser
    {
        $shortAliases = [];

        foreach ($rules as $rule) {
            $ruleName = $rule->getName();

            /** @var array<string, string> $aliases */
            $aliases = $rule::getCliAliases();

            foreach ($aliases as $alias => $optionName) {
                $shortAliases[$alias] = [
                    'rule' => $ruleName,
                    'option' => $optionName,
                ];
            }
        }

        return new RuleOptionsParser($shortAliases);
    }

    /**
     * Creates parser with aliases from given rule classes.
     *
     * Uses reflection to get rule NAME constant without instantiation.
     *
     * @param list<class-string<RuleInterface>> $ruleClasses
     */
    public function createFromClasses(array $ruleClasses): RuleOptionsParser
    {
        $shortAliases = [];

        foreach ($ruleClasses as $ruleClass) {
            $ruleName = $this->getRuleName($ruleClass);

            /** @var array<string, string> $aliases */
            $aliases = $ruleClass::getCliAliases();

            foreach ($aliases as $alias => $optionName) {
                $shortAliases[$alias] = [
                    'rule' => $ruleName,
                    'option' => $optionName,
                ];
            }
        }

        return new RuleOptionsParser($shortAliases);
    }

    /**
     * Gets rule name from class constant NAME or by instantiation (fallback).
     *
     * @param class-string<RuleInterface> $ruleClass
     */
    private function getRuleName(string $ruleClass): string
    {
        $reflection = new ReflectionClass($ruleClass);

        // Try to get NAME constant (preferred - no instantiation)
        if ($reflection->hasConstant('NAME')) {
            $name = $reflection->getConstant('NAME');
            if (\is_string($name)) {
                return $name;
            }
        }

        // Fallback: create instance with default options
        $optionsClass = $ruleClass::getOptionsClass();
        $options = $optionsClass::fromArray([]);
        $rule = new $ruleClass($options);

        return $rule->getName();
    }
}
