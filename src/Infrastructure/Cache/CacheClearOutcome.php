<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

final readonly class CacheClearOutcome
{
    public function __construct(
        public bool $complete,
        public int $remaining,
        public string $directory,
        public ?string $reason,
    ) {}
}
