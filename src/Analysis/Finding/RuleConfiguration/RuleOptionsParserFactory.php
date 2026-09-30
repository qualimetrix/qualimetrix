<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration;

use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;

/**
 * Creates RuleOptionsParser with short aliases collected from rules.
 */
final readonly class RuleOptionsParserFactory
{
    /**
     * Creates parser with aliases from given rule classes.
     *
     * Uses reflection to get rule NAME constant without instantiation.
     *
     * @param list<class-string<RuleDefinitionInterface>> $ruleClasses
     */
    public function createFromClasses(array $ruleClasses): RuleOptionsParser
    {
        $shortAliases = [];
        $optionsClasses = [];

        foreach ($ruleClasses as $ruleClass) {
            $ruleName = RuleNameReader::read($ruleClass);
            $optionsClasses[$ruleName] = $ruleClass::getOptionsClass();

            $aliases = CliAliasReader::read($ruleClass);

            foreach ($aliases as $alias => $optionName) {
                $shortAliases[$alias] = [
                    'rule' => $ruleName,
                    'option' => $optionName,
                ];
            }
        }

        return new RuleOptionsParser($shortAliases, $optionsClasses);
    }

    /** @param list<RuleMetadata> $producers */
    public function createFromMetadata(array $producers): RuleOptionsParser
    {
        $aliases = [];
        $optionsClasses = [];
        foreach ($producers as $producer) {
            $optionsClasses[$producer->name] = $producer->optionsClass;
            foreach ($producer->aliases as $alias => $option) {
                $aliases[$alias] = ['rule' => $producer->name, 'option' => $option];
            }
        }

        return new RuleOptionsParser($aliases, $optionsClasses);
    }
}
