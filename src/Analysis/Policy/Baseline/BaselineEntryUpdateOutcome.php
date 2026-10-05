<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

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
    ) {}

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

    public static function skipped(BaselineIdentity $identity): self
    {
        return new self($identity, BaselineUpdateDisposition::Skipped);
    }
}
