<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Rule\Override;

use Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideAxis;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\ThresholdOverrideRequest;

final class NonNegativeOverrideThresholds
{
    public static function judge(ThresholdOverrideRequest $request): ?OverrideValidationFailure
    {
        return self::warningProblem($request->warning)
            ?? self::negativeFailure(OverrideAxis::Error, $request->error);
    }

    public static function warningProblem(int|float|null $warning): ?OverrideValidationFailure
    {
        return self::negativeFailure(OverrideAxis::Warning, $warning);
    }

    public static function format(int|float $value): string
    {
        return \is_int($value) ? (string) $value : \sprintf('%g', $value);
    }

    private static function negativeFailure(OverrideAxis $axis, int|float|null $value): ?OverrideValidationFailure
    {
        if ($value !== null && $value < 0) {
            return new OverrideValidationFailure(
                code: 'negative_' . $axis->value,
                message: \sprintf('%s threshold must be non-negative (got %s)', $axis->value, self::format($value)),
            );
        }

        return null;
    }
}
