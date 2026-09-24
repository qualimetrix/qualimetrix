<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Profiler\Contract;

/**
 * Per span name: the summed duration in milliseconds and the count of spans
 * that stopped themselves, and how many did not — ended by an enclosing
 * span's stop, or still running — whose time is therefore not in `total`.
 */
final readonly class ProfileSummary
{
    /** @param array<string, array{total: float, count: int, unstopped: int}> $spans */
    public function __construct(public array $spans = []) {}
}
