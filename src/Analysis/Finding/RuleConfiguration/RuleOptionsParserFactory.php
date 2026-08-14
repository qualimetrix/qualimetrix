<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration;

use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;

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

        foreach ($ruleClasses as $ruleClass) {
            $ruleName = RuleNameReader::read($ruleClass);

            $aliases = CliAliasReader::read($ruleClass);

            foreach ($aliases as $alias => $optionName) {
                $shortAliases[$alias] = [
                    'rule' => $ruleName,
                    'option' => $optionName,
                ];
            }
        }

        return new RuleOptionsParser($shortAliases);
    }
}
