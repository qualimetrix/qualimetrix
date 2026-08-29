<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection;

/**
 * One finding {@see RuleExclusionLedgerAttributor} attributed to a per-rule
 * ledger half, carrying the matched pattern alongside the public
 * {@see SuppressedFinding} so the attributor's hit-tracking (feeding
 * zero-count detection) does not have to re-derive it from the pattern
 * matcher a second time.
 */
final readonly class LedgerHit
{
    public function __construct(
        public SuppressedFinding $suppressedFinding,
        public SuppressionMechanism $mechanism,
        public string $ruleName,
        public ?string $pattern,
    ) {}
}
