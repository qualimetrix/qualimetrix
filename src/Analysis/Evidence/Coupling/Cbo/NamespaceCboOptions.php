<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling\Cbo;

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
 * Options for namespace-level CBO (Coupling Between Objects) checks.
 *
 * CBO = |Ca ∪ Ce|
 * - Low CBO (<14): weakly coupled
 * - Medium CBO (14-19): acceptable (warning)
 * - High CBO (>=20): tightly coupled (error)
 *
  * @qmx-threshold coupling.instability 0.833335 -- This subject implements an owner-declared outward protocol with intentionally few incoming readers; the finite boundary preserves visibility of additional dependencies.
 */
final readonly class NamespaceCboOptions implements LevelOptionsInterface, ThresholdAwareOptionsInterface
{
    use ThresholdOptionQueries;

    use StandardOverrideValidatorTrait;

    public function __construct(
        public bool $enabled = true,
        public int $warning = 14,
        public int $error = 20,
        public int $minClassCount = 3,
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        $thresholds = ThresholdPair::fromResolved($config, RuleOptionSurface::bandFor(self::class, 'threshold'), 14, 20);
        return new self(
            enabled: $config->boolean('enabled', true),
            warning: $thresholds->integerWarning(),
            error: $thresholds->integerError(),
            minClassCount: $config->integer('min-class-count', 3),
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
            'error' => RuleOptionShape::integer()->orNull(),
            'min-class-count' => RuleOptionShape::integer()->orNull(),
            'threshold' => RuleOptionShape::integer()->orNull(),
            'warning' => RuleOptionShape::integer()->orNull(),
        ])->band('threshold', 'warning', 'error', BandDirection::Rising);
    }

    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static(
            enabled: $this->enabled,
            warning: $warning !== null ? (int) $warning : $this->warning,
            error: $error !== null ? (int) $error : $this->error,
            minClassCount: $this->minClassCount,
        );
    }

    protected function thresholdPair(): ThresholdPair
    {
        return new ThresholdPair($this->warning, $this->error);
    }

}
