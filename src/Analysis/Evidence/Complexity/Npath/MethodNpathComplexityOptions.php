<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Complexity\Npath;

use Qualimetrix\Analysis\Finding\Contract\Rule\IntegerThresholdOptionsTrait;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\BandDirection;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;

/**
 * Options for callable-level NPath complexity checks.
 */
final readonly class MethodNpathComplexityOptions implements LevelOptionsInterface, ThresholdAwareOptionsInterface
{
    use IntegerThresholdOptionsTrait;

    public function __construct(
        public bool $enabled = true,
        public int $warning = 200,
        public int $error = 1000,
    ) {}

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'enabled' => RuleOptionShape::boolean()->orNull(),
            'error' => RuleOptionShape::integer()->orNull(),
            'threshold' => RuleOptionShape::integer()->orNull(),
            'warning' => RuleOptionShape::integer()->orNull(),
        ])->band('threshold', 'warning', 'error', BandDirection::Rising);
    }

}
