<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Rule\Override;

/**
 * Structured result for a rejected `@qmx-threshold` override.
 *
 * Returned by OverrideValidatorInterface implementations when the supplied
 * warning/error pair violates the rule-specific constraint. The `code`
 * field is a stable machine identifier for cross-referencing in docs and
 * structured output (SARIF/JSON); `message` is the human-readable
 * explanation surfaced through ThresholdDiagnostic.
 */
final readonly class OverrideValidationFailure
{
    public function __construct(
        public string $code,
        public string $message,
        public ?string $hint = null,
    ) {}
}
