<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Rule\Override;

/**
 * Per-rule validation strategy for `@qmx-threshold` annotations.
 *
 * Implementations encode the constraint that defines what (warning, error)
 * pairs make sense for a particular kind of rule:
 * - {@see StandardOverrideValidator} — exceeding-threshold rules (W ≤ E)
 * - {@see InvertedOverrideValidator} — below-threshold rules (W ≥ E)
 * - {@see IndependentAxisValidator} — multi-metric rules (no W↔E relation)
 * - {@see WarningOnlyValidator} — single-threshold rules (error must be null)
 *
 * Implementations MUST be stateless and safe to share across amphp/parallel
 * worker processes. Validators receive `$errorWasExplicit` to distinguish a
 * user-written `error=N` from the shorthand form (`@qmx-threshold X N`)
 * which expands to `W=N, E=N` at parse time.
 */
interface OverrideValidatorInterface
{
    public function validate(
        int|float|null $warning,
        int|float|null $error,
        bool $errorWasExplicit,
    ): ?OverrideValidationFailure;
}
