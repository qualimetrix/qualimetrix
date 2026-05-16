<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Rule\Override;

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

        if ($errorWasExplicit && $error !== null) {
            return new OverrideValidationFailure(
                code: 'error_not_supported',
                message: 'this rule only honours the warning threshold; the error value would be ignored',
                hint: 'omit `error=...` or use the shorthand form `@qmx-threshold <rule> <warning>`',
            );
        }

        return null;
    }

    private static function format(int|float $value): string
    {
        return \is_int($value) ? (string) $value : \sprintf('%g', $value);
    }
}
