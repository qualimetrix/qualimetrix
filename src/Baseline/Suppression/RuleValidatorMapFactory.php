<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline\Suppression;

use LogicException;
use Qualimetrix\Core\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Core\Rule\Override\OverrideValidatorInterface;
use Qualimetrix\Core\Rule\RuleInterface;
use Qualimetrix\Core\Rule\ThresholdAwareOptionsInterface;
use ReflectionClass;

/**
 * Builds the `rule-name => OverrideValidatorInterface` map consumed by
 * {@see ThresholdOverrideExtractor}.
 *
 * The map is populated once per process (main DI and each parallel
 * worker boot). For every registered rule whose Options class supports
 * threshold overrides — either directly (`ThresholdAwareOptionsInterface`)
 * or through a hierarchical wrapper that exposes level-specific
 * ThresholdAware Options — the rule's NAME constant is resolved via
 * reflection and the validator is obtained from the static
 * `getOverrideValidator()` accessor. Rules without thresholds are
 * skipped silently.
 *
 * Selection criterion matches {@see \Qualimetrix\Analysis\Pipeline\AnalysisPipeline::ruleSupportsThresholdOverrides()}
 * — the two methods must agree on which rules accept `@qmx-threshold`,
 * otherwise hierarchical rules silently bypass per-rule validation.
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
            if (!class_exists($ruleClass)) {
                // Defensive symmetry with WorkerBootstrap::canInstantiate() — a
                // misconfigured rule class string would otherwise surface as
                // a low-level ReflectionException inside a worker task.
                continue;
            }

            $ruleName = self::resolveRuleName($ruleClass);
            if ($ruleName === null) {
                continue;
            }

            $optionsClass = $ruleClass::getOptionsClass();
            $validator = self::resolveValidator($optionsClass);
            if ($validator === null) {
                continue;
            }

            $map[$ruleName] = $validator;
        }

        return $map;
    }

    /**
     * @param class-string $optionsClass
     */
    private static function resolveValidator(string $optionsClass): ?OverrideValidatorInterface
    {
        if (is_subclass_of($optionsClass, ThresholdAwareOptionsInterface::class)) {
            return $optionsClass::getOverrideValidator();
        }

        // Hierarchical rules (complexity, cbo, instability, …) keep
        // level-specific ThresholdAware Options behind a non-ThresholdAware
        // root. AnalysisPipeline::ruleSupportsThresholdOverrides() walks
        // those levels at analyse-time; the parser must use the same
        // criterion or the rule's annotations silently skip validation.
        if (!is_subclass_of($optionsClass, HierarchicalRuleOptionsInterface::class)) {
            return null;
        }

        $rootOptions = $optionsClass::fromArray([]);
        \assert($rootOptions instanceof HierarchicalRuleOptionsInterface);

        $selected = null;
        $selectedSource = null;

        foreach ($rootOptions->getSupportedLevels() as $level) {
            $levelOptions = $rootOptions->forLevel($level);
            if (!$levelOptions instanceof ThresholdAwareOptionsInterface) {
                continue;
            }

            $levelOptionsClass = $levelOptions::class;
            $levelValidator = $levelOptionsClass::getOverrideValidator();

            if ($selected === null) {
                $selected = $levelValidator;
                $selectedSource = $levelOptionsClass;

                continue;
            }

            // The parser binds one validator per rule name, but hierarchical
            // levels are addressed by the same annotation. Disagreement on
            // strategy across levels would make the validator's verdict
            // depend on which level the rule decides to apply — fail-fast
            // so the divergence is visible at boot.
            if ($levelValidator !== $selected) {
                throw new LogicException(\sprintf(
                    'Hierarchical Options class %s exposes level Options with disagreeing override validators: %s (first level) vs %s (subsequent). All ThresholdAware levels of a hierarchical rule must share the same validator strategy.',
                    $optionsClass,
                    $selectedSource ?? '<unknown>',
                    $levelOptionsClass,
                ));
            }
        }

        return $selected;
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
