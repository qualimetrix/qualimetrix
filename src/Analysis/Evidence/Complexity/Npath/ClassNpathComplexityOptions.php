<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Complexity\Npath;

use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\MaximumThresholdOptionsTrait;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\BandDirection;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionSurface;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\ThresholdPair;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\ThresholdParser;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;

/**
 * Options for class-level NPath complexity checks (max NPath among methods).
 */
final readonly class ClassNpathComplexityOptions implements LevelOptionsInterface, ThresholdAwareOptionsInterface
{
    use MaximumThresholdOptionsTrait;

    public function __construct(
        public bool $enabled = false,
        public int $maxWarning = 500,
        public int $maxError = 1000,
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        $thresholds = ThresholdPair::fromResolved($config, RuleOptionSurface::bandFor(self::class, 'threshold'), 500, 1000);
        return new self(
            enabled: $config->boolean('enabled', ThresholdParser::wasWritten($config, RuleOptionSurface::bandFor(self::class, 'threshold'))),
            maxWarning: $thresholds->integerWarning(),
            maxError: $thresholds->integerError(),
        );
    }

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
