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
        $stickyProtects = ($parent->mode & 01000) !== 0 && ($entry->owner === $effectiveUid || $entry->owner === 0);
        $placeable = $foreign || $writable;
        $swappable = $foreign || ($writable && !$stickyProtects);

        if ($entry->type === 0040000) {
            $placeable = $swappable;
        }

        $changedBy = $foreign ? 'user ' . $parent->owner : ($other ? 'others' : ($group ? 'group ' . $parent->group : null));

        return new self($placeable, $swappable, $changedBy, $foreign, $group, $other, $other ? 'others' : ($group ? 'group ' . $parent->group : null));
    }

    public function forTrace(string $directory, bool $effectiveUidIsRoot): ?PathExposure
    {
        if (!$this->swappableByOthers && !$this->placeableByOthers) {
            return null;
        }

        if ($effectiveUidIsRoot && !$this->groupWritable && !$this->otherWritable) {
            return null;
        }

        if ($effectiveUidIsRoot && $this->foreignParent) {
            return new PathExposure($directory, $this->writableBy ?? 'others');
        }

        return new PathExposure($directory, $this->changedBy ?? 'others');
    }
}
