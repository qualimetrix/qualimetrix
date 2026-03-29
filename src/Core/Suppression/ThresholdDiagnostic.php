<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Suppression;

/**
 * Represents a validation diagnostic for an invalid @qmx-threshold annotation.
 *
 * Produced by ThresholdOverrideExtractor when an annotation has:
 * - Invalid syntax (unparseable values)
 * - Negative threshold values
 * - Warning threshold greater than error threshold
 * - Duplicate rule annotations on the same symbol
 */
final readonly class ThresholdDiagnostic
{
    public function __construct(
        public int $line,
        public string $message,
    ) {}
}
