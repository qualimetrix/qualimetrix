<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

final readonly class EntryFacts
{
    public function __construct(public int $owner, public int $type) {}

    /** @param array<string|int, int> $stat */
    public static function fromStat(array $stat): self
    {
        return new self($stat['uid'], $stat['mode'] & 0170000);
    }
}
