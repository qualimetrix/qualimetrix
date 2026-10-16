<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

final readonly class FileIdentity
{
    public function __construct(public int $device, public int $inode, public int $type) {}

    /** @param array<string|int, int> $stat */
    public static function fromStat(array $stat): self
    {
        return new self($stat['dev'], $stat['ino'], $stat['mode'] & 0170000);
    }

    public function sameAs(self $other): bool
    {
        return $this->device === $other->device && $this->inode === $other->inode && $this->type === $other->type;
    }

    public function isCharacterDevice(): bool
    {
        return $this->type === 0020000;
    }
}
