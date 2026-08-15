<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Profiler\Contract;

final readonly class ProfileSummary
{
    /** @param array<string, array{total: float, count: int, avg: float, memory: int, peak_memory: int}> $spans */
    public function __construct(public array $spans = []) {}
}
