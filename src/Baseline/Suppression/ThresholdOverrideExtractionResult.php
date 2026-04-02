<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline\Suppression;

use Qualimetrix\Core\Suppression\ThresholdDiagnostic;
use Qualimetrix\Core\Suppression\ThresholdOverride;

/**
 * Result of extracting `@qmx-threshold` annotations from a single AST node.
 *
 * Contains both valid overrides and diagnostics for invalid annotations.
 */
final readonly class ThresholdOverrideExtractionResult
{
    /**
     * @param list<ThresholdOverride> $overrides Valid threshold overrides
     * @param list<ThresholdDiagnostic> $diagnostics Diagnostics for invalid annotations
     */
    public function __construct(
        public array $overrides,
        public array $diagnostics,
    ) {}
}
