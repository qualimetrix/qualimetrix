<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling\ClassRank;

use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\BandDirection;

use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionSurface;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\ThresholdPair;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\StandardOverrideValidatorTrait;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOptionQueries;

/**
 * Configuration options for ClassRank rule.
 *
 * ClassRank share is PageRank probability multiplied by the logical measured graph population.
 * One share is the uniform graph probability; higher shares identify dependency hubs.
 *
 * Thresholds:
 * - Warning: 5.0 (class has notably high importance in the graph)
 * - Error: 10.0 (class is a critical hub, high change impact)
 */
final readonly class ClassRankOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface
{
    use ThresholdOptionQueries;

    use StandardOverrideValidatorTrait;

    public function __construct(
        public bool $enabled = true,
        public float $warning = 5.0,
        public float $error = 10.0,
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        $thresholds = ThresholdPair::fromResolved($config, RuleOptionSurface::bandFor(self::class, 'threshold'), 5.0, 10.0);
        return new self(
            enabled: $config->boolean('enabled', true),
            warning: $thresholds->warning,
            error: $thresholds->error,
        );
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'error' => RuleOptionShape::number()->orNull(),
            'threshold' => RuleOptionShape::number()->orNull(),
            'warning' => RuleOptionShape::number()->orNull(),
        ])->band('threshold', 'warning', 'error', BandDirection::Rising);
    }

    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static(
            enabled: $this->enabled,
            warning: $warning !== null ? (float) $warning : $this->warning,
            error: $error !== null ? (float) $error : $this->error,
        );
    }

    protected function thresholdPair(): ThresholdPair
    {
        return new ThresholdPair($this->warning, $this->error);
    }

}
