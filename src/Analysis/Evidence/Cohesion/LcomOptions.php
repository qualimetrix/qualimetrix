<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

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
 * Options for LcomRule.
 *
 * LCOM4 (Lack of Cohesion of Methods) thresholds:
 * - LCOM4 <= 2: cohesive class (no finding)
 * - LCOM4 3-4: warning (class may have multiple responsibilities)
 * - LCOM4 >= 5: error (class clearly does too much, should be split)
 *
 * Industry standard: LCOM4 >= 5 indicates serious cohesion problems.
 */
final readonly class LcomOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface
{
    use ThresholdOptionQueries;

    use StandardOverrideValidatorTrait;

    /**
     * @param int $minMethods Minimum number of LCOM graph vertices after exclusions and stateless merging
     * @param list<string>|null $excludeMethods Method names to exclude from LCOM graph (e.g., lifecycle hooks)
     */
    public function __construct(
        public bool $enabled = true,
        public int $warning = 3,
        public int $error = 5,
        public bool $excludeReadonly = true,
        public int $minMethods = 3,
        public ?array $excludeMethods = null,
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        $thresholds = ThresholdPair::fromResolved($config, RuleOptionSurface::bandFor(self::class, 'threshold'), 3, 5);
        return new self(
            enabled: $config->boolean('enabled', true),
            warning: $thresholds->integerWarning(),
            error: $thresholds->integerError(),
            excludeReadonly: $config->boolean('exclude-readonly', true),
            minMethods: $config->integer('min-methods', 3),
            excludeMethods: $config->list('exclude-methods') === null ? null : $config->strings('exclude-methods', []),
        );
    }

    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static(
            enabled: $this->enabled,
            warning: $warning !== null ? (int) $warning : $this->warning,
            error: $error !== null ? (int) $error : $this->error,
            excludeReadonly: $this->excludeReadonly,
            minMethods: $this->minMethods,
            excludeMethods: $this->excludeMethods,
        );
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'error' => RuleOptionShape::integer()->orNull(),
            'exclude-methods' => RuleOptionShape::listOf(RuleOptionShape::text())->orNull(),
            'exclude-readonly' => RuleOptionShape::boolean()->orNull(),
            'min-methods' => RuleOptionShape::integer()->orNull(),
            'threshold' => RuleOptionShape::integer()->orNull(),
            'warning' => RuleOptionShape::integer()->orNull(),
        ])->band('threshold', 'warning', 'error', BandDirection::Rising);
    }
    protected function thresholdPair(): ThresholdPair
    {
        return new ThresholdPair($this->warning, $this->error);
    }

}
