<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Composer\Contract;

/** Why a candidate yielded no Composer root, without any run-scope verdict. */
final readonly class ComposerRootOmission
{
    private function __construct(
        public string $cause,
        public string $candidate,
        public ?string $startDirectory,
        public ?string $lastDirectory,
        public int $visitedLevels,
    ) {}

    public static function unresolvable(string $candidate): self
    {
        return new self('unresolvable', $candidate, null, null, 0);
    }

    public static function filesystemRootReached(string $candidate, string $start, string $last, int $visited): self
    {
        return new self('filesystem-root', $candidate, $start, $last, $visited);
    }

    public static function walkLimitReached(string $candidate, string $start, string $last, int $visited): self
    {
        return new self('walk-limit', $candidate, $start, $last, $visited);
    }
}
