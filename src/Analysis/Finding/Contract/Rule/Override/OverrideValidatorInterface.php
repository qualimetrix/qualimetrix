<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule\Override;

use Qualimetrix\Analysis\Finding\Rule\Override\OverrideValidationFailure;

/**
 * Per-rule validation strategy for `@qmx-threshold` annotations.
 *
 * Implementations encode the constraint that defines what (warning, error)
 * pairs make sense for a particular kind of rule:
 * - {@see StandardOverrideValidator} — exceeding-threshold rules (W ≤ E)
 * - {@see InvertedOverrideValidator} — below-threshold rules (W ≥ E)
 * - {@see IndependentAxisValidator} — multi-metric rules (no W↔E relation)
 * - {@see WarningOnlyValidator} — single-threshold rules (no authored error axis)
 *
 * Implementations MUST be stateless and safe to share across amphp/parallel
 * worker processes. The request distinguishes explicitly authored axes from
 * a shorthand number, which applies equally to warning and error.
 */
interface OverrideValidatorInterface
{
    public function validate(ThresholdOverrideRequest $request): ?OverrideValidationFailure;
}
