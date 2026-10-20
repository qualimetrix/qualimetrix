<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Size\PropertyCount;

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
 * Options for the property count rule.
 */
final readonly class PropertyCountOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface
{
    use ThresholdOptionQueries;

    use StandardOverrideValidatorTrait;

    public function __construct(
        public bool $enabled = true,
        public int $warning = 15,
        public int $error = 20,
        public bool $excludeReadonly = true,
        public bool $excludePromotedOnly = true,
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        $thresholds = ThresholdPair::fromResolved($config, RuleOptionSurface::bandFor(self::class, 'threshold'), 15, 20);
        return new self(
            enabled: $config->boolean('enabled', true),
            warning: $thresholds->integerWarning(),
            error: $thresholds->integerError(),
            excludeReadonly: $config->boolean('exclude-readonly', true),
            excludePromotedOnly: $config->boolean('exclude-promoted-only', true),
        );
    }

    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static(
            enabled: $this->enabled,
            warning: $warning !== null ? (int) $warning : $this->warning,
            error: $error !== null ? (int) $error : $this->error,
            excludeReadonly: $this->excludeReadonly,
            excludePromotedOnly: $this->excludePromotedOnly,
        );
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'error' => RuleOptionShape::integer()->orNull(),
            'exclude-promoted-only' => RuleOptionShape::boolean()->orNull(),
            'exclude-readonly' => RuleOptionShape::boolean()->orNull(),
            'threshold' => RuleOptionShape::integer()->orNull(),
            'warning' => RuleOptionShape::integer()->orNull(),
        ])->band('threshold', 'warning', 'error', BandDirection::Rising);
    }
    protected function thresholdPair(): ThresholdPair
    {
        return new ThresholdPair($this->warning, $this->error);
    }

}
