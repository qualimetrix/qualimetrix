<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract;

use LogicException;

use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;

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
 * The criterion below is mechanical — what a rule's Options class *can*
 * carry. What the run answers is what the rule *declares*, through its
 * `SUPPORTS_THRESHOLD_OVERRIDE` constant
 * ({@see \Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface::supportsThresholdOverride()}).
 * The two are pinned against each other by
 * `ChannelUniverseCoverageTest::everyRulesDeclaredThresholdSupportMatchesWhatItsOptionsCanHonour()`,
 * because a rule that declares support its options cannot honour promises a
 * retune the runtime never performs, and one that stays silent while its
 * options are threshold-aware silently loses a feature. They must not drift
 * here either: a hierarchical rule missed by this walk bypasses per-rule
 * validation while still being addressable.
 */
final readonly class RuleValidatorMapFactory
{
    /**
     * @param list<class-string<RuleDefinitionInterface>> $ruleClasses
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

            $ruleName = RuleNameReader::read($ruleClass);

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
        // root, so the walk has to descend into the levels: stopping at the
        // root would skip per-rule validation for exactly the rules whose
        // annotations carry a level.
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
}
