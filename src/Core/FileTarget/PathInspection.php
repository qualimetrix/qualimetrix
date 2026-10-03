<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

final readonly class PathInspection
{
    /**
     * @param list<array{path: string, identity: FileIdentity}> $directories
     * @param list<PathExposure> $exposure
     */
    public function __construct(
        public array $directories,
        public array $exposure,
        public bool $streamExposed = false,
    ) {}

    public function withDirectory(string $path, FileIdentity $identity, EntryControl $control, string $parent, int $effectiveUid): self
    {
        $exposure = $this->exposure;
        if ($control->swappableByOthers) {
            $exposure = self::includeExposure($exposure, $control, $parent, $effectiveUid);
        }

        return new self(
            [...$this->directories, ['path' => $path, 'identity' => $identity]],
            $exposure,
            $this->streamExposed || $control->swappableByOthers,
        );
    }

    public function withTerminalEntry(int $type, EntryControl $control, string $parent, int $effectiveUid): self
    {
        $exposure = $this->exposure;
        if ($type === 0100000 && $control->placeableByOthers) {
            $exposure = self::includeExposure($exposure, $control, $parent, $effectiveUid);
        }

        return new self(
            $this->directories,
            $exposure,
            $this->streamExposed || ($type !== 0100000 && $control->swappableByOthers),
        );
    }

    public function sameDirectoriesAs(self $other): bool
    {
        if (\count($this->directories) !== \count($other->directories)) {
            return false;
        }

        foreach ($this->directories as $index => $directory) {
            $candidate = $other->directories[$index];
            if ($directory['path'] !== $candidate['path'] || !$directory['identity']->sameAs($candidate['identity'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<PathExposure> $exposure
     *
     * @return list<PathExposure>
     */
    private static function includeExposure(array $exposure, EntryControl $control, string $parent, int $effectiveUid): array
    {
        $trace = $control->forTrace($parent, $effectiveUid);
        if ($trace !== null) {
            $exposure[] = $trace;
        }

        return $exposure;
    }
}
