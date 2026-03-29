<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Rule;

/**
 * Interface for rule options that support threshold overrides.
 *
 * Options classes with warning/error thresholds implement this to support
 *
 * @qmx-threshold annotations. Options without thresholds (boolean rules like
 * code smells) do not need to implement this.
 */
interface ThresholdAwareOptionsInterface
{
    /**
     * Returns a copy with overridden thresholds.
     *
     * Null values keep the original threshold.
     */
    public function withOverride(int|float|null $warning, int|float|null $error): static;
}
