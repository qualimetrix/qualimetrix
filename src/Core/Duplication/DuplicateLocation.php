<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Duplication;

/**
 * Represents a single location of a duplicated code block.
 */
final readonly class DuplicateLocation
{
    public function __construct(
        public string $file,
        public int $startLine,
        public int $endLine,
    ) {}

    public function lineCount(): int
    {
        return $this->endLine - $this->startLine + 1;
    }

    public function toString(): string
    {
        return \sprintf('%s:%d-%d', $this->file, $this->startLine, $this->endLine);
    }
}
