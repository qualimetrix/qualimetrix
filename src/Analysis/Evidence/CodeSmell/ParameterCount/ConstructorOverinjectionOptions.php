<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell\ParameterCount;

use Qualimetrix\Analysis\Finding\Contract\Rule\IntegerThresholdOptionsTrait;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\BandDirection;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;

/**
 * Options for ConstructorOverinjectionRule.
 *
 * Checks the number of constructor parameters (dependencies).
 * Thresholds based on common industry standards:
 * - <= 7 parameters: acceptable
 * - 8+ parameters: warning, consider using a parameter object or splitting responsibilities
 * - 12+ parameters: error, definitely needs refactoring
 */
final readonly class ConstructorOverinjectionOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface
{
    use IntegerThresholdOptionsTrait;

    public function __construct(
        public bool $enabled = true,
        public int $warning = 8,
        public int $error = 12,
    ) {}

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'error' => RuleOptionShape::integer()->orNull(),
            'threshold' => RuleOptionShape::integer()->orNull(),
            'warning' => RuleOptionShape::integer()->orNull(),
        ])->band('threshold', 'warning', 'error', BandDirection::Rising);
    }

}
