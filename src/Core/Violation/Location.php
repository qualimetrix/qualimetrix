<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Violation;

final readonly class Location
{
    public function __construct(
        public string $file,
        public ?int $line = null,
    ) {}

    public function toString(): string
    {
        if ($this->line === null) {
            return $this->file;
        }

        return \sprintf('%s:%d', $this->file, $this->line);
    }
}
