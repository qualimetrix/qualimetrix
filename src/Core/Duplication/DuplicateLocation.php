<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Duplication;

use Qualimetrix\Core\Path\RelativePath;

/**
 * Represents a single location of a duplicated code block.
 */
final readonly class DuplicateLocation
{
    public function __construct(
        public RelativePath $file,
        public int $startLine,
        public int $endLine,
    ) {}

    public function lineCount(): int
    {
        return $this->endLine - $this->startLine + 1;
    }

    /**
     * Wire-surface string of the file path (ADR 0015 bridge for comparator/array-key sites).
     */
    public function pathString(): string
    {
        return $this->file->value();
    }

    public function toString(): string
    {
        return \sprintf('%s:%d-%d', $this->file->value(), $this->startLine, $this->endLine);
    }
}
