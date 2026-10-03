<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule\Override;

use Qualimetrix\Analysis\Finding\Rule\Override\NonNegativeOverrideThresholds;
use Qualimetrix\Analysis\Finding\Rule\Override\OverrideValidationFailure;

/**
 * Validator for rules where only the warning threshold is meaningful.
 *
 * Used by rules whose `withOverride()` discards the error parameter (the
 * God Class rule maps warning to `minCriteria`; error has no equivalent
 * knob). Accepts the shorthand form `@qmx-threshold X N` — which the
 * parser expands to (W=N, E=N) — because the user did not explicitly
 * supply an error value. Rejects the explicit form `error=N` with a
 * descriptive diagnostic so the user knows the value would be ignored.
 */
final class WarningOnlyValidator implements OverrideValidatorInterface
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    public function validate(ThresholdOverrideRequest $request): ?OverrideValidationFailure
    {
        $failure = NonNegativeOverrideThresholds::warningProblem($request->warning);
        if ($failure !== null) {
            return $failure;
        }

        if ($request->hasAuthored(OverrideAxis::Error)) {
            return new OverrideValidationFailure(
                code: 'error_not_supported',
                message: 'this rule only honours the warning threshold; the error value would be ignored',
                hint: 'omit `error=...` or use the shorthand form `@qmx-threshold <rule> <warning>`',
            );
        }

        return null;
    }
}
