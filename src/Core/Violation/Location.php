<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Violation;

use InvalidArgumentException;

final readonly class Location
{
    public function __construct(
        public string $file,
        public ?int $line = null,
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
        return new self('');
    }

    /**
     * Returns true if this location has no associated file (architectural violation).
     */
    public function isNone(): bool
    {
        return $this->file === '';
    }

    public function toString(): string
    {
        if ($this->line === null) {
            return $this->file;
        }

        return \sprintf('%s:%d', $this->file, $this->line);
    }
}
