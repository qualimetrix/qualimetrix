<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

final readonly class DirectoryFacts
{
    public function __construct(public int $owner, public int $group, public int $mode) {}

    /** @param array<string|int, int> $stat */
    public static function fromStat(array $stat): self
    {
        return new self($stat['uid'], $stat['gid'], $stat['mode']);
    }
}
