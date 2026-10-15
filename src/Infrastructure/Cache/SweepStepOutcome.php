<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

final readonly class SweepStepOutcome
{
    public function __construct(public int $shards, public int $removed, public int $bytes, public int $nextShard, public ?string $cursorFailure = null) {}
}
