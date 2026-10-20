<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Maintainability;

use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\BandDirection;

use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionSurface;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\ThresholdPair;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\InvertedOverrideValidator;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOptionQueries;

/**
 * Options for MaintainabilityRule.
 *
 * Maintainability Index thresholds:
 * - MI >= 40: good maintainability (no finding)
 * - MI 20-39: moderate maintainability (warning)
 * - MI < 20: poor maintainability (error)
 *
 * Note: Lower MI is worse, so thresholds work in reverse.
 */
final readonly class MaintainabilityOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface
{
    use ThresholdOptionQueries;

    public function __construct(
        public bool $enabled = true,
        public float $warning = 40.0,
        public float $error = 20.0,
        public bool $excludeTests = true,
        public int $minStatements = 10,
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        $thresholds = ThresholdPair::fromResolved($config, RuleOptionSurface::bandFor(self::class, 'threshold'), 40.0, 20.0);
        return new self(
            enabled: $config->boolean('enabled', true),
            warning: $thresholds->warning,
            error: $thresholds->error,
            excludeTests: $config->boolean('exclude-tests', true),
            minStatements: $config->integer('min-statements', 10),
        );
    }

    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static(
            enabled: $this->enabled,
            warning: $warning !== null ? (float) $warning : $this->warning,
            error: $error !== null ? (float) $error : $this->error,
            excludeTests: $this->excludeTests,
            minStatements: $this->minStatements,
        );
    }

    public static function getOverrideValidator(): OverrideValidatorInterface
    {
        return InvertedOverrideValidator::instance();
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'error' => RuleOptionShape::number()->orNull(),
            'exclude-tests' => RuleOptionShape::boolean()->orNull(),
            'min-statements' => RuleOptionShape::integer()->orNull(),
            'threshold' => RuleOptionShape::number()->orNull(),
            'warning' => RuleOptionShape::number()->orNull(),
        ])->band('threshold', 'warning', 'error', BandDirection::Falling);
    }
    protected function thresholdPair(): ThresholdPair
    {
        return new ThresholdPair($this->warning, $this->error, BandDirection::Falling);
    }

}
