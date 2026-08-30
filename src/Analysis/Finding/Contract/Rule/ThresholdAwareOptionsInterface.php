<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideValidatorInterface;

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
     * The warning boundary this object holds right now, on the axis its
     * channel reports — or why it cannot name one.
     *
     * "Right now" is load-bearing: on the copy {@see self::withOverride()}
     * returns, this is the overridden number, not the one `qmx.yaml`
     * configured. A caller that wants the configured value asks an object no
     * override has touched, which is what `baseline:explain` does.
     *
     * **The member is not always the one `getSeverity()` compares against.**
     * Two rules decide inside their own `analyze()` and leave `getSeverity()`
     * a stub: `design.god-class` warns from `matchedCount >= minCriteria`, and
     * `design.data-class` emits while `woc <= wocThreshold`. Both hold a real
     * boundary, and both name a member `getSeverity()` never reads — which is
     * also why {@see self::withOverride()} is the only witness those two have.
     *
     * A member that merely gates whether the rule runs at all — `minAfferent`,
     * `minClassCount`, `minStatements`, `wmcThreshold` — is not a boundary and
     * is never the answer.
     */
    public function warningBoundary(): int|float|NoConfiguredBoundary;

    /**
     * Returns the per-rule validation strategy for `@qmx-threshold` overrides.
     *
     * Declared static because the validator is class-level metadata, not
     * instance state — the parser builds its rule-name → validator map at
     * boot time without instantiating any Options.
     */
    public static function getOverrideValidator(): OverrideValidatorInterface;
}
