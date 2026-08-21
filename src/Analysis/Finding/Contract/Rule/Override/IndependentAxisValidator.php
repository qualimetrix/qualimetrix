<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule\Override;

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

        return null;
    }

    private static function format(int|float $value): string
    {
        return \is_int($value) ? (string) $value : \sprintf('%g', $value);
    }
}
