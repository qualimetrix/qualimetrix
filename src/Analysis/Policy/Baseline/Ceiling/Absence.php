<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\Ceiling;

final readonly class Absence
{
    private function __construct(public string $status, public ?IncomparabilityReason $reason = null) {}

    public static function stale(): self
    {
        return new self('stale');
    }
    public static function unmeasured(IncomparabilityReason $reason): self
    {
        return new self('unmeasured', $reason);
    }
    public static function outsideCoverage(): self
    {
        return new self('outside-coverage', IncomparabilityReason::OutsideCoverage);
    }
    public static function notCompared(IncomparabilityReason $reason): self
    {
        return new self('not-compared', $reason);
    }
}
