<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\Inheritance;

use Qualimetrix\Analysis\Finding\Contract\Rule\IntegerThresholdOptionsTrait;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\BandDirection;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;

/**
 * Options for InheritanceRule.
 *
 * DIT (Depth of Inheritance Tree) thresholds based on Lorenz & Kidd research:
 * - DIT <= 3: good inheritance design (no finding)
 * - DIT = 4-5: moderate depth, review needed (warning)
 * - DIT >= 6: deep hierarchy, likely design issue (error)
 *
 * Deep hierarchies increase coupling and reduce understandability.
 */
final readonly class InheritanceOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface
{
    use IntegerThresholdOptionsTrait;

    public function __construct(
        public bool $enabled = true,
        public int $warning = 4,
        public int $error = 6,
    ) {}

    /**
     * Get severity for a given DIT value.
     *
     * Higher DIT = deeper inheritance = more complexity.
     */
    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'error' => RuleOptionShape::integer()->orNull(),
            'threshold' => RuleOptionShape::integer()->orNull(),
            'warning' => RuleOptionShape::integer()->orNull(),
        ])->band('threshold', 'warning', 'error', BandDirection::Rising);
    }

}
