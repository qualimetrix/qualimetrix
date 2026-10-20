<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Complexity\Cognitive;

use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\MaximumThresholdOptionsTrait;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\BandDirection;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;

/**
 * Options for class-level cognitive complexity checks.
 *
 * Checks maximum cognitive complexity among class methods.
 */
final readonly class ClassCognitiveComplexityOptions implements LevelOptionsInterface, ThresholdAwareOptionsInterface
{
    use MaximumThresholdOptionsTrait;

    public function __construct(
        public bool $enabled = true,
        public int $maxWarning = 30,
        public int $maxError = 50,
    ) {}

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'enabled' => RuleOptionShape::boolean()->orNull(),
            'max-error' => RuleOptionShape::integer()->orNull(),
            'max-warning' => RuleOptionShape::integer()->orNull(),
            'threshold' => RuleOptionShape::integer()->orNull(),
        ])->band('threshold', 'max-warning', 'max-error', BandDirection::Rising);
    }

}
