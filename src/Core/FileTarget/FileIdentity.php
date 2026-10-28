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

    /**
     * The identity of a regular file, or null for a failed stat, another file
     * type or inode 0, which some filesystems report for every file.
     *
     * @param array<string|int, int>|false $stat
     */
    public static function ofRegularFile(array|false $stat): ?self
    {
        $identity = $stat === false ? null : self::fromStat($stat);

        return $identity !== null && $identity->inode !== 0 && $identity->isRegularFile() ? $identity : null;
    }

    public function sameAs(self $other): bool
    {
        return $this->device === $other->device && $this->inode === $other->inode && $this->type === $other->type;
    }

    public function isRegularFile(): bool
    {
        return $this->type === 0100000;
    }

    public function isCharacterDevice(): bool
    {
        return $this->type === 0020000;
    }
}
