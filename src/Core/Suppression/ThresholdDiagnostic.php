<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Suppression;

/**
 * Represents a validation diagnostic for an invalid `@qmx-threshold` annotation.
 *
 * Produced by ThresholdOverrideExtractor when an annotation has:
 * - Invalid syntax (unparseable values, duplicate annotations)
 * - Rule-specific override violations enforced via the per-rule
 *   `OverrideValidatorInterface` (e.g. warning > error for standard rules,
 *   warning < error for inverted rules, explicit error= for warning-only rules)
 *
 * `$code` is a stable machine identifier for cross-referencing — `null`
 * for parser-level diagnostics (syntax/duplicate), a validator code
 * (`negative_warning`, `warning_exceeds_error`, etc.) for rule-specific
 * rejections.
 */
final readonly class ThresholdDiagnostic
{
    public function __construct(
        public int $line,
        public string $message,
        public ?string $code = null,
        public ?string $hint = null,
    ) {}
}
