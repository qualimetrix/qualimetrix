<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

use Qualimetrix\Core\Path\AbsolutePath;

final readonly class ResolvedTarget
{
    /**
     * @param list<array{path: string, identity: FileIdentity}> $directories
     * @param list<PathExposure> $exposure
     */
    public function __construct(
        public string $spelling,
        public TargetKind $kind,
        public ?AbsolutePath $path,
        public ?int $descriptor,
        public ?FileIdentity $identity,
        public array $directories,
        public array $exposure,
        public bool $streamExposed = false,
    ) {}

    public function sameAs(self $other): bool
    {
        if ($this->kind !== $other->kind || $this->descriptor !== $other->descriptor || $this->path?->value() !== $other->path?->value()) {
            return false;
        }

        if (($this->identity === null) !== ($other->identity === null)) {
            return false;
        }

        if ($this->identity !== null && ($other->identity === null || !$this->identity->sameAs($other->identity))) {
            return false;
        }

        if (\count($this->directories) !== \count($other->directories)) {
            return false;
        }

        foreach ($this->directories as $index => $directory) {
            if ($directory['path'] !== $other->directories[$index]['path'] || !$directory['identity']->sameAs($other->directories[$index]['identity'])) {
                return false;
            }
        }

        return true;
    }
}
