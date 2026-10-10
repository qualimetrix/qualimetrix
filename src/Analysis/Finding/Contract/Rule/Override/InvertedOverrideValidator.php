<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule\Override;

use Qualimetrix\Analysis\Finding\Rule\Override\NonNegativeOverrideThresholds;
use Qualimetrix\Analysis\Finding\Rule\Override\OverrideValidationFailure;

/**
 * Validator for inverted-threshold rules: below the threshold is bad, so W ≥ E.
 *
 * Used by rules where higher metric values indicate better code — the
 * Maintainability Index (MI ≥ 40 good, MI < 20 critical) and type
 * coverage percentages. Rejects negative thresholds and error values
 * that exceed warning values (the inversion of the standard check).
 */
final class InvertedOverrideValidator implements OverrideValidatorInterface
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    public function validate(ThresholdOverrideRequest $request): ?OverrideValidationFailure
    {
        $failure = NonNegativeOverrideThresholds::judge($request);
        if ($failure !== null) {
            return $failure;
        }

        $warning = $request->warning;
        $error = $request->error;
        if ($warning !== null && $error !== null && $warning < $error) {
            return new OverrideValidationFailure(
                code: 'error_exceeds_warning',
                message: \sprintf(
                    'warning threshold (%s) must not be below error threshold (%s) — this rule treats higher values as better',
                    NonNegativeOverrideThresholds::format($warning),
                    NonNegativeOverrideThresholds::format($error),
                ),
                hint: 'inverted-threshold rules require warning >= error (e.g. maintainability warns at MI=40, errors at MI=20)',
            );
        }

        return null;
    }
}
