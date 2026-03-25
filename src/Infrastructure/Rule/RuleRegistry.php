<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Rule;

use Qualimetrix\Core\Rule\RuleInterface;
use Qualimetrix\Infrastructure\Rule\Exception\ConflictingCliAliasException;
use ReflectionClass;

/**
 * Registry of rule classes.
 *
 * Works with class names instead of instances, enabling metadata extraction
 * via reflection without instantiation. This is essential for lazy rule loading.
 */
final readonly class RuleRegistry implements RuleRegistryInterface
{
    /** @var list<class-string<RuleInterface>> */
    private array $ruleClasses;

    /**
     * @param list<class-string<RuleInterface>> $ruleClasses
     */
    public function __construct(array $ruleClasses)
    {
        $this->ruleClasses = array_values($ruleClasses);
    }

    /**
     * Creates instances of all rules with default options.
     *
     * Note: This method instantiates rules. For metadata-only access,
     * use getClasses() and getAllCliAliases() instead.
     *
     * @return iterable<RuleInterface>
     */
    public function getAll(): iterable
    {
        foreach ($this->ruleClasses as $ruleClass) {
            $optionsClass = $ruleClass::getOptionsClass();
            $options = $optionsClass::fromArray([]);

            yield new $ruleClass($options);
        }
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
            $ruleName = $this->getRuleName($ruleClass);

            /** @var array<string, string> $ruleAliases */
            $ruleAliases = $ruleClass::getCliAliases();

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
