<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Rule\Override;

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

    public function validate(
        int|float|null $warning,
        int|float|null $error,
        bool $errorWasExplicit,
    ): ?OverrideValidationFailure {
        if ($warning !== null && $warning < 0) {
            return new OverrideValidationFailure(
                code: 'negative_warning',
                message: \sprintf('warning threshold must be non-negative (got %s)', self::format($warning)),
            );
        }

        if ($error !== null && $error < 0) {
            return new OverrideValidationFailure(
                code: 'negative_error',
                message: \sprintf('error threshold must be non-negative (got %s)', self::format($error)),
            );
        }

        if ($warning !== null && $error !== null && $warning > $error) {
            return new OverrideValidationFailure(
                code: 'warning_exceeds_error',
                message: \sprintf(
                    'warning threshold (%s) must not exceed error threshold (%s)',
                    self::format($warning),
                    self::format($error),
                ),
            );
        }

        return null;
    }

    private static function format(int|float $value): string
    {
        return \is_int($value) ? (string) $value : \sprintf('%g', $value);
    }
}
