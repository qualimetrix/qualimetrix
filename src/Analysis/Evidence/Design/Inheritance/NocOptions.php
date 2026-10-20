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
 * Configuration options for NOC (Number of Children) rule.
 *
 * NOC measures how many classes directly extend a given class.
 * High NOC indicates:
 * - Wide reuse/inheritance
 * - High impact of changes (affects many subclasses)
 * - Potential violation of Liskov Substitution Principle
 *
 * Thresholds based on Chidamber & Kemerer research:
 * - Warning: 10 (many direct children, changes affect many classes)
 * - Error: 15 (too many children, consider using interfaces or composition)
 */
final readonly class NocOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface
{
    use IntegerThresholdOptionsTrait;

    public function __construct(
        public bool $enabled = true,
        public int $warning = 10,
        public int $error = 15,
    ) {}

    /**
     * Get severity for a given NOC value.
     *
     * Higher NOC = more children = wider impact of changes.
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
