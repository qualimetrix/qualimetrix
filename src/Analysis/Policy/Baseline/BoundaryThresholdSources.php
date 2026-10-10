<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;

/** Threshold evidence from the current run and configured rules. */
final readonly class BoundaryThresholdSources
{
    /**
     * @param array<string, list<ThresholdOverride>> $thresholdOverridesByFile
     * @param array<string, array<string, int|float>> $configuredThresholds
     */
    public function __construct(
        public array $thresholdOverridesByFile,
        public array $configuredThresholds,
    ) {}
}
