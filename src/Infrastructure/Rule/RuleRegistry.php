<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Rule;

use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Infrastructure\Rule\Exception\ConflictingCliAliasException;

/**
 * Registry of rule classes.
 *
 * Works with class names instead of instances, enabling metadata extraction
 * via reflection without instantiation. This is essential for lazy rule loading.
 *
 * The registry never instantiates rules: rules may declare constructor
 * dependencies beyond their Options object, so only the DI container can build
 * them. Consumers that need instances receive them from the container (see
 * {@see \Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleCompilerPass}).
 */
final readonly class RuleRegistry implements RuleRegistryInterface
{
    /** @var list<class-string<RuleDefinitionInterface>> */
    private array $ruleClasses;

    /**
     * @param list<class-string<RuleDefinitionInterface>> $ruleClasses
     */
    public function __construct(array $ruleClasses)
    {
        $this->ruleClasses = array_values($ruleClasses);
    }

    public function getClasses(): array
    {
        return $this->ruleClasses;
    }

    /**
     * Collects all CLI aliases using reflection (no instantiation needed).
     *
     * @throws ConflictingCliAliasException when two rules define the same alias
     *
     * @return array<string, array{rule: string, option: string}>
     */
    public function getAllCliAliases(): array
    {
        $aliases = [];

        foreach ($this->ruleClasses as $ruleClass) {
            $ruleName = RuleNameReader::read($ruleClass);

            $ruleAliases = CliAliasReader::read($ruleClass);

            foreach ($ruleAliases as $alias => $optionName) {
                if (isset($aliases[$alias])) {
                    throw new ConflictingCliAliasException(
                        $alias,
                        $aliases[$alias]['rule'],
                        $ruleName,
                    );
                }

                $aliases[$alias] = [
                    'rule' => $ruleName,
                    'option' => $optionName,
                ];
            }
        }

        return $aliases;
    }
}
