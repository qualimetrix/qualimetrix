<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

final readonly class SweepPolicy
{
    public function __construct(public int $maxAgeSeconds = 2592000, public int $shardQuotaBytes = 8388608, public int $shardsPerStep = 16) {}

    public static function default(): self
    {
        return new self();
    }
}
