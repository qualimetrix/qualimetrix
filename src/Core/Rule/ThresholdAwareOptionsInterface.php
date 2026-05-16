<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Rule;

use Qualimetrix\Core\Rule\Override\OverrideValidatorInterface;

/**
 * Interface for rule options that support threshold overrides.
 *
 * Options classes with warning/error thresholds implement this to support
 * `@qmx-threshold` annotations. Options without thresholds (boolean rules
 * like code smells) do not need to implement this.
 *
 * The static {@see self::getOverrideValidator()} returns the per-rule
 * validation strategy that the parser consults before applying an
 * override. Implementations typically use `StandardOverrideValidatorTrait`
 * (W ≤ E for exceeding-threshold rules) or return a specific validator
 * for inverted, independent-axis, or warning-only rules.
 */
interface ThresholdAwareOptionsInterface
{
    /**
     * Returns a copy with overridden thresholds.
     *
     * Null values keep the original threshold.
     */
    public function withOverride(int|float|null $warning, int|float|null $error): static;

    /**
     * Returns the per-rule validation strategy for `@qmx-threshold` overrides.
     *
     * Declared static because the validator is class-level metadata, not
     * instance state — the parser builds its rule-name → validator map at
     * boot time without instantiating any Options.
     */
    public static function getOverrideValidator(): OverrideValidatorInterface;
}
