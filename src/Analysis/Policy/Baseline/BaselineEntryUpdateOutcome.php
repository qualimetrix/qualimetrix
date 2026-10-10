<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use Qualimetrix\Analysis\Finding\Contract\AcceptedLevel;

/**
 * What happened to one entry during a `baseline:update` run (ADR 0017).
 */
final readonly class BaselineEntryUpdateOutcome
{
    private function __construct(
        public BaselineIdentity $identity,
        public BaselineUpdateDisposition $disposition,
        public ?BaselineUpdateRefusalReason $refusalReason = null,
        public ?string $reasonCode = null,
        public ?AcceptedLevel $previousLevel = null,
        public ?AcceptedLevel $currentLevel = null,
        public ?EntrySelector $selector = null,
    ) {}

    public static function accepted(BaselineIdentity $identity): self
    {
        return new self($identity, BaselineUpdateDisposition::Accepted);
    }

    public static function reRecorded(BaselineEntry $previous, BaselineEntry $current): self
    {
        return new self(
            $previous->identity,
            BaselineUpdateDisposition::ReRecorded,
            previousLevel: new AcceptedLevel($previous->magnitudes, $previous->count),
            currentLevel: new AcceptedLevel($current->magnitudes, $current->count),
        );
    }

    public static function removed(BaselineEntry $entry): self
    {
        return new self(
            $entry->identity,
            BaselineUpdateDisposition::Removed,
            reasonCode: BaselineCleanupReason::ExclusionsRemovedPopulation->value,
            selector: $entry->selector(),
        );
    }

    public static function updated(BaselineIdentity $identity): self
    {
        return new self($identity, BaselineUpdateDisposition::Updated);
    }

    public static function unchanged(BaselineIdentity $identity): self
    {
        return new self($identity, BaselineUpdateDisposition::Unchanged);
    }

    public static function notCompared(BaselineIdentity $identity, string $reasonCode): self
    {
        return new self($identity, BaselineUpdateDisposition::NotCompared, reasonCode: $reasonCode);
    }

    public static function refused(BaselineIdentity $identity, BaselineUpdateRefusalReason $reason): self
    {
        return new self($identity, BaselineUpdateDisposition::Refused, $reason);
    }

    public static function skipped(BaselineIdentity $identity, ?string $reasonCode = null): self
    {
        return new self($identity, BaselineUpdateDisposition::Skipped, reasonCode: $reasonCode);
    }
}
