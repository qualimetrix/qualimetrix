<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling\Instability;

use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;

use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\BandDirection;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionSurface;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\ThresholdPair;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\StandardOverrideValidatorTrait;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOptionQueries;

/**
 * Options for class-level instability checks.
 *
 * Instability range: [0, 1]
 * - 0: maximally stable (only incoming dependencies)
 * - 1: maximally unstable (only outgoing dependencies)
 *
  * @qmx-threshold coupling.instability 0.833335 -- This subject implements an owner-declared outward protocol with intentionally few incoming readers; the finite boundary preserves visibility of additional dependencies.
 */
final readonly class ClassInstabilityOptions implements LevelOptionsInterface, ThresholdAwareOptionsInterface
{
    use ThresholdOptionQueries;

    use StandardOverrideValidatorTrait;

    public function __construct(
        public bool $enabled = true,
        public float $maxWarning = 0.8,
        public float $maxError = 0.95,
        public int $minAfferent = 1,
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        $thresholds = ThresholdPair::fromResolved($config, RuleOptionSurface::bandFor(self::class, 'threshold'), 0.8, 0.95);
        return new self(
            enabled: $config->boolean('enabled', true),
            maxWarning: $thresholds->warning,
            maxError: $thresholds->error,
            minAfferent: $config->integer('min-afferent', 1),
        );
    }

    /**
     * Keep `threshold` here even though no constructor parameter names it:
     * `ThresholdPair::fromResolved()` reads that key through its default
     * `$thresholdKey`.
     */
    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'enabled' => RuleOptionShape::boolean()->orNull(),
            'max-error' => RuleOptionShape::number()->orNull(),
            'max-warning' => RuleOptionShape::number()->orNull(),
            'min-afferent' => RuleOptionShape::integer()->orNull(),
            'threshold' => RuleOptionShape::number()->orNull(),
        ])->band('threshold', 'max-warning', 'max-error', BandDirection::Rising);
    }

    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static(
            enabled: $this->enabled,
            maxWarning: $warning !== null ? (float) $warning : $this->maxWarning,
            maxError: $error !== null ? (float) $error : $this->maxError,
            minAfferent: $this->minAfferent,
        );
    }

    protected function thresholdPair(): ThresholdPair
    {
        return new ThresholdPair($this->maxWarning, $this->maxError);
    }

}
