<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline\Suppression;

use Qualimetrix\Core\Rule\Override\OverrideValidatorInterface;
use Qualimetrix\Core\Rule\RuleInterface;
use Qualimetrix\Core\Rule\ThresholdAwareOptionsInterface;
use ReflectionClass;

/**
 * Builds the `rule-name => OverrideValidatorInterface` map consumed by
 * {@see ThresholdOverrideExtractor}.
 *
 * The map is populated once per process (main DI and each parallel
 * worker boot). For every registered rule whose Options class implements
 * {@see ThresholdAwareOptionsInterface}, the rule's NAME constant is
 * resolved via reflection (no instantiation) and the validator is
 * obtained from the static `getOverrideValidator()` accessor. Rules
 * without thresholds are skipped silently.
 */
final readonly class RuleValidatorMapFactory
{
    /**
     * @param list<class-string<RuleInterface>> $ruleClasses
     *
     * @return array<string, OverrideValidatorInterface>
     */
    public static function build(array $ruleClasses): array
    {
        $map = [];

        foreach ($ruleClasses as $ruleClass) {
            $ruleName = self::resolveRuleName($ruleClass);
            if ($ruleName === null) {
                continue;
            }

            $optionsClass = $ruleClass::getOptionsClass();
            if (!is_subclass_of($optionsClass, ThresholdAwareOptionsInterface::class)) {
                continue;
            }

            $map[$ruleName] = $optionsClass::getOverrideValidator();
        }

        return $map;
    }

    /**
     * @param class-string<RuleInterface> $ruleClass
     */
    private static function resolveRuleName(string $ruleClass): ?string
    {
        $reflection = new ReflectionClass($ruleClass);

        if ($reflection->hasConstant('NAME')) {
            $name = $reflection->getConstant('NAME');
            if (\is_string($name)) {
                return $name;
            }
        }

        return null;
    }
}
