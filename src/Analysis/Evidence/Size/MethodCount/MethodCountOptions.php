<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Size\MethodCount;

use Qualimetrix\Analysis\Finding\Contract\Rule\IntegerThresholdOptionsTrait;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\BandDirection;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;

/**
 * Options for MethodCountRule.
 *
 * Checks the number of methods in a class.
 * Thresholds based on common industry standards:
 * - fewer than 20 methods: below the warning boundary
 * - 20-29 methods: warning, class may be doing too much
 * - 30 or more methods: error, class should be split
 */
final readonly class MethodCountOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface
{
    use IntegerThresholdOptionsTrait;

    public function __construct(
        public bool $enabled = true,
        public int $warning = 20,
        public int $error = 30,
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
