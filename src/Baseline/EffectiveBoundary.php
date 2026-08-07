<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

use Qualimetrix\Core\Suppression\ThresholdOverride;

/**
 * The effective boundary for one identity, and where each part of it comes
 * from — `baseline:explain`'s "`ccn` ≤ 25 from baseline; `qmx.yaml` says 10;
 * annotation raises it to 40" (§7 of the baseline-ceiling plan).
 *
 * Each source is independently nullable, and absence is never conflated
 * with a zero-valued source: a symbol with no baseline entry for this
 * identity has {@see $baseline} `null`, not a source claiming to have
 * accepted nothing.
 */
final readonly class EffectiveBoundary
{
    /**
     * @param BaselineIdentity $identity the symbol, channel and (when present) dependency
     *                                   edge this boundary is about
     * @param ?EffectiveBoundaryBaselineSource $baseline `null` when no baseline entry
     *                                                   addresses this identity
     * @param int|float|null $configuredThreshold the rule's `qmx.yaml`-configured boundary
     *                                            (before any `@qmx-threshold` override),
     *                                            `null` when it could not be resolved
     * @param ?ThresholdOverride $annotation the `@qmx-threshold` override in scope for this
     *                                       symbol and rule, `null` when none applies
     */
    public function __construct(
        public BaselineIdentity $identity,
        public ?EffectiveBoundaryBaselineSource $baseline,
        public int|float|null $configuredThreshold,
        public ?ThresholdOverride $annotation,
    ) {}
}
