<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Size\ClassCount;

use Qualimetrix\Analysis\Finding\Contract\Rule\IntegerThresholdOptionsTrait;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\BandDirection;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;

/**
 * Options for ClassCountRule.
 *
 * Checks the number of classes in a namespace.
 * Thresholds based on package cohesion principles:
 * - fewer than 15 classes: below the warning boundary
 * - 15-24 classes: warning, namespace may be doing too much
 * - 25 or more classes: error, namespace should be split into subnamespaces
 */
final readonly class ClassCountOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface
{
    use IntegerThresholdOptionsTrait;

    public function __construct(
        public bool $enabled = true,
        public int $warning = 15,
        public int $error = 25,
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
