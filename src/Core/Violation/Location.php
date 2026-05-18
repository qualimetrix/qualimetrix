<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Violation;

use InvalidArgumentException;
use Qualimetrix\Core\Path\RelativePath;

final readonly class Location
{
    /**
     * @param bool $precise When true, the line points to the exact location of the problem
     *                      (e.g., a specific code smell occurrence). When false, the line is
     *                      merely the declaration line of the enclosing symbol (class/method).
     *                      Formatters use this hint to decide whether to display the line number.
     */
    public function __construct(
        public ?RelativePath $file,
        public ?int $line = null,
        public bool $precise = false,
    ) {
        if ($this->line !== null && $this->line < 1) {
            throw new InvalidArgumentException(
                \sprintf('Line number must be >= 1 or null, got %d', $this->line),
            );
        }
    }

    /**
     * Creates a location for architectural violations not tied to a specific file.
     */
    public static function none(): self
    {
        return new self(null, null, false);
    }

    /**
     * Returns true if this location has no associated file (architectural violation).
     */
    public function isNone(): bool
    {
        return $this->file === null;
    }

    /**
     * Wire-surface string of the file path; empty string for {@see none()}.
     *
     * Used by formatters, comparators, and array-key writes that need the legacy
     * string-shape semantics during the gradual VO migration (ADR 0015).
     */
    public function pathString(): string
    {
        return $this->file?->value() ?? '';
    }

    public function toString(): string
    {
        if ($this->file === null) {
            return '';
        }

        if ($this->line === null) {
            return $this->file->value();
        }

        return \sprintf('%s:%d', $this->file->value(), $this->line);
    }
}
