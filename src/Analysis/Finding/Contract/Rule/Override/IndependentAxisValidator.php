<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule\Override;

use Qualimetrix\Analysis\Finding\Rule\Override\NonNegativeOverrideThresholds;
use Qualimetrix\Analysis\Finding\Rule\Override\OverrideValidationFailure;

/**
 * Validator for rules whose warning and error overrides act on different metrics.
 *
 * Used by rules that combine multiple metric conditions and route the
 * warning and error halves of `@qmx-threshold` to independent axes — the
 * Data Class rule maps warning to the WOC threshold and error to the WMC
 * threshold, both upper bounds on unrelated metrics. Only non-negativity is
 * enforced, so a warning value below the error value is not an ordering
 * error here.
 */
final class IndependentAxisValidator implements OverrideValidatorInterface
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    public function validate(ThresholdOverrideRequest $request): ?OverrideValidationFailure
    {
        return NonNegativeOverrideThresholds::judge($request);
    }
}
