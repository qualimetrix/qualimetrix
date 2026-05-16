<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Rule\Override;

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

        if ($warning !== null && $error !== null && $warning < $error) {
            return new OverrideValidationFailure(
                code: 'error_exceeds_warning',
                message: \sprintf(
                    'warning threshold (%s) must not be below error threshold (%s) — this rule treats higher values as better',
                    self::format($warning),
                    self::format($error),
                ),
                hint: 'inverted-threshold rules require warning >= error (e.g. maintainability warns at MI=40, errors at MI=20)',
            );
        }

        return null;
    }

    private static function format(int|float $value): string
    {
        return \is_int($value) ? (string) $value : \sprintf('%g', $value);
    }
}
