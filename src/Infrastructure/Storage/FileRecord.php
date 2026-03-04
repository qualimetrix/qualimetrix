<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Storage;

/**
 * Value object representing a file in the storage.
 */
final readonly class FileRecord
{
    public function __construct(
        public string $path,
        public string $contentHash,
        public int $mtime,
        public int $size,
        public ?string $namespace = null,
        public ?int $collectedAt = null,
    ) {}
}
