<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract;

use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;

/** Immutable source-level suppression and threshold extraction result. */
final readonly class SourceControls
{
    /**
     * @param list<Suppression> $suppressions
     * @param list<ThresholdOverride> $thresholdOverrides
     * @param list<ThresholdDiagnostic> $thresholdDiagnostics
     */
    public function __construct(
        public array $suppressions = [],
        public array $thresholdOverrides = [],
        public array $thresholdDiagnostics = [],
    ) {}
}
