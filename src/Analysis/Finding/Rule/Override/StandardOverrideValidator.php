<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Rule\Override;

use Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\ThresholdOverrideRequest;

/**
 * Default validator: exceeding the threshold is bad, so W ≤ E.
 *
 * Used by the majority of rules — anything where higher metric values
 * indicate worse code (CCN, NPath, CBO, method count, etc.). Rejects
 * negative thresholds and warning values that exceed error values.
 */
final class StandardOverrideValidator implements OverrideValidatorInterface
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
        if ($warning !== null && $error !== null && $warning > $error) {
            return new OverrideValidationFailure(
                code: 'warning_exceeds_error',
                message: \sprintf(
                    'warning threshold (%s) must not exceed error threshold (%s)',
                    NonNegativeOverrideThresholds::format($warning),
                    NonNegativeOverrideThresholds::format($error),
                ),
            );
        }

        return null;
    }
}
