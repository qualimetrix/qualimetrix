<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

final readonly class EntryControl
{
    private function __construct(
        public bool $placeableByOthers,
        public bool $swappableByOthers,
        public ?string $changedBy,
        private bool $foreignParent,
        private bool $groupWritable,
        private bool $otherWritable,
        private ?string $writableBy,
    ) {}

    public static function of(DirectoryFacts $parent, EntryFacts $entry, int $effectiveUid): self
    {
        $foreign = $parent->owner !== $effectiveUid && $parent->owner !== 0;
        $group = ($parent->mode & 0020) !== 0;
        $other = ($parent->mode & 0002) !== 0;
        $writable = $group || $other;
        $swappable = $foreign || ($writable && !self::stickyProtects($parent, $entry, $effectiveUid));
        $placeable = $entry->type === 0040000 ? $swappable : ($foreign || $writable);
        $writableBy = self::writableBy($parent);
        $changedBy = $foreign ? 'user ' . $parent->owner : $writableBy;

        return new self($placeable, $swappable, $changedBy, $foreign, $group, $other, $writableBy);
    }

    public function forTrace(string $directory, int $effectiveUid): ?PathExposure
    {
        if (!$this->swappableByOthers && !$this->placeableByOthers) {
            return null;
        }

        if ($effectiveUid === 0) {
            return $this->rootTrace($directory);
        }

        return new PathExposure($directory, $this->changedBy ?? 'others');
    }

    private function rootTrace(string $directory): ?PathExposure
    {
        if (!$this->groupWritable && !$this->otherWritable) {
            return null;
        }

        return new PathExposure($directory, $this->foreignParent ? ($this->writableBy ?? 'others') : ($this->changedBy ?? 'others'));
    }

    private static function stickyProtects(DirectoryFacts $parent, EntryFacts $entry, int $effectiveUid): bool
    {
        return ($parent->mode & 01000) !== 0 && ($entry->owner === $effectiveUid || $entry->owner === 0);
    }

    private static function writableBy(DirectoryFacts $parent): ?string
    {
        if (($parent->mode & 0002) !== 0) {
            return 'others';
        }

        return ($parent->mode & 0020) !== 0 ? 'group ' . $parent->group : null;
    }
}
