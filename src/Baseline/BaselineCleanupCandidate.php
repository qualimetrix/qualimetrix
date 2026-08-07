<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

/**
 * One entry `baseline:cleanup` offers for removal: what it is, why it is
 * offered, and the selector a user passes to `--remove` to confirm it (§7 of
 * the baseline-ceiling plan).
 */
final readonly class BaselineCleanupCandidate
{
    /**
     * @param ?InertEntryReason $inertReason present exactly when {@see reason}
     *                                       is {@see BaselineCleanupReason::Inert}
     */
    public function __construct(
        public EntrySelector $selector,
        public string $description,
        public BaselineCleanupReason $reason,
        public ?InertEntryReason $inertReason = null,
    ) {}
}
